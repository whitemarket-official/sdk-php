<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GeneralExportException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\StaleExportException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Export\Rows\BatchJsonRows;
use WhiteMarket\Sdk\Export\Rows\CsvRows;
use WhiteMarket\Sdk\Export\Rows\JsonMachineRows;
use WhiteMarket\Sdk\Export\Rows\RowIterator;
use WhiteMarket\Sdk\Export\Rows\RowSource;
use WhiteMarket\Sdk\Export\Rows\YajlStreamRows;
use WhiteMarket\Sdk\Transport\BodyReader;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\Transport\SystemClock;

/**
 * The public export files (SDK_SPEC 3.10): prices, streamed products, the freshness sidecar, raw downloads. No authentication, so it
 * works standalone: {@see create()} needs nothing, and `WhiteMarketClient::$exports` is the same class.
 *
 * Failures: a status other than 200 is an {@see HttpException}, a network failure, a refused redirect or an undecodable or
 * over-sized body a {@see TransportException}, a body that is not the expected JSON a {@see DecodeException}, a
 * destination that cannot be written a {@see GeneralExportException}, data older than allowed a
 * {@see StaleExportException}.
 */
final readonly class ExportClient
{
    private const MAX_APP_ID = 2_147_483_647;

    private const PRODUCTS_PATH = 'products';

    /**
     * @internal use {@see create()}; public so tests can inject the clock
     */
    public function __construct(
        private ExportTransport $transport,
        private StreamFactoryInterface $streamFactory,
        private ExportOptions $options = new ExportOptions(),
        private Clock $clock = new SystemClock(),
    ) {}

    /**
     * Without `$httpClient` the SDK uses Guzzle with the connect and idle timeouts of `$options`, a streamed body and
     * no total timeout. An injected client is used as is (the same trust boundary as for the GraphQL calls): the SDK
     * cannot set its timeouts, and a client that buffers the whole response (Guzzle with `allow_url_fopen` off) defeats
     * the streaming of {@see download()}.
     *
     * @param CacheInterface|null $cache holds the `ETag` and `Last-Modified` of files fetched with `conditional: true`
     *                                   (never a body); share one between workers to share the "already seen" state
     */
    public static function create(
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ExportOptions $options = new ExportOptions(),
        ?CacheInterface $cache = null,
        RetryPolicy $retryPolicy = new RetryPolicy(),
        LoggerInterface $logger = new NullLogger(),
    ): self {
        $messageFactory = GuzzleClientFactory::messageFactory();

        return new self(
            new ExportTransport(
                $httpClient ?? GuzzleClientFactory::createForExports($options),
                $requestFactory ?? $messageFactory,
                $options,
                $cache,
                $retryPolicy,
                $logger,
            ),
            $streamFactory ?? $messageFactory,
            $options,
        );
    }

    /**
     * The freshness sidecar `<file>.meta.json` of an export file.
     *
     * @param int $app the Steam app id (730 is CS2)
     *
     * @throws \InvalidArgumentException `$app` is not a positive 32-bit integer
     * @throws HttpException
     * @throws TransportException
     * @throws DecodeException
     */
    public function meta(
        ExportKind $kind,
        int $app = 730,
        ExportWindow $window = ExportWindow::All,
        ExportFormat $format = ExportFormat::Json,
    ): ExportMeta {
        return $this->fetchMeta($this->fileUrl($kind, $app, $window, $format) . '.meta.json');
    }

    /**
     * Downloads and parses the prices file (JSON; about 21 thousand rows).
     *
     * @param int|null $maxAgeSeconds fetch the meta first and fail with {@see StaleExportException}, before the file is
     *                                downloaded, when its `data_as_of` is more than this many seconds old
     * @param bool $conditional send the `ETag` / `Last-Modified` remembered for this file and return
     *                          {@see NotModified} when the server says it did not change; needs a cache
     *
     * @throws \InvalidArgumentException `$app` is not a positive 32-bit integer, `$maxAgeSeconds` is negative, or
     *                                   `$conditional` without a cache
     * @throws StaleExportException
     * @throws HttpException
     * @throws TransportException the body is over `maxBodyBytes` decoded, or not valid gzip
     * @throws DecodeException
     */
    public function prices(
        int $app = 730,
        ExportWindow $window = ExportWindow::All,
        ?int $maxAgeSeconds = null,
        bool $conditional = false,
    ): PriceList|NotModified {
        $url = $this->fileUrl(ExportKind::Prices, $app, $window, ExportFormat::Json);
        if ($conditional && !$this->transport->hasCache()) {
            throw new \InvalidArgumentException('A conditional request needs a cache: pass one to ExportClient::create().');
        }
        $this->assertFresh($url, $maxAgeSeconds);

        $body = $conditional ? $this->transport->getIfModified($url) : $this->transport->get($url);
        if ($body instanceof NotModified) {
            return $body;
        }
        $list = new PriceList(ExportMapper::priceRows($body->read($this->options->maxBodyBytes)));
        if ($conditional) {
            $this->transport->remember($body);
        }

        return $list;
    }

    /**
     * The rows of the prices file, in file order. The file is small: the body is read whole (bounded by
     * `maxBodyBytes` decoded) and parsed before this returns, so it saves no memory over {@see prices()}; it exists
     * for the iterator shape of the spec. The requests are sent by this call, not by the first iteration.
     *
     * @return \Generator<int, PriceRow>
     *
     * @throws \InvalidArgumentException `$app` is not a positive 32-bit integer, or `$maxAgeSeconds` is negative
     * @throws StaleExportException
     * @throws HttpException
     * @throws TransportException the body is over `maxBodyBytes` decoded, or not valid gzip
     * @throws DecodeException
     */
    public function streamPrices(
        int $app = 730,
        ExportWindow $window = ExportWindow::All,
        ?int $maxAgeSeconds = null,
    ): \Generator {
        $url = $this->fileUrl(ExportKind::Prices, $app, $window, ExportFormat::Json);
        $this->assertFresh($url, $maxAgeSeconds);

        return $this->yieldRows($this->fetchPriceRows($url));
    }

    /**
     * The rows of the products file (millions of lots), in file order, parsed as the body arrives: one row is in memory
     * at a time, and the body has no size limit. The requests are sent by this call, so a status other than 200 fails
     * here; a body that breaks off or a row that does not decode fails at that row of the iteration, after the rows
     * before it. Stopping the iteration early closes the connection.
     *
     * @param ExportFormat $format JSON, or CSV with a header line naming the same fields
     * @param int|null $maxAgeSeconds fetch the meta first and fail with {@see StaleExportException}, before the file is
     *                                downloaded, when its `data_as_of` is more than this many seconds old
     *
     * @return \Iterator<int, ProductRow> single pass, keyed by the row's index in the file; a row whose
     *                                    `product_category` is null is skipped (the server sends a few), so keys may
     *                                    have gaps; JSON is parsed by `ExportOptions::$jsonStreamDecoder`
     *
     * @throws \InvalidArgumentException `$app` is not a positive 32-bit integer, or `$maxAgeSeconds` is negative
     * @throws StaleExportException
     * @throws HttpException
     * @throws TransportException also while iterating: the body is not valid gzip, is cut short or unreadable
     * @throws DecodeException while iterating
     */
    public function streamProducts(
        int $app = 730,
        ExportWindow $window = ExportWindow::All,
        ExportFormat $format = ExportFormat::Json,
        ?int $maxAgeSeconds = null,
    ): \Iterator {
        $url = $this->fileUrl(ExportKind::Products, $app, $window, $format);
        $this->assertFresh($url, $maxAgeSeconds);
        $reader = $this->transport->get($url)->reader();

        return new RowIterator($this->rowSource($reader, $format, self::PRODUCTS_PATH), ExportMapper::productRow(...), self::PRODUCTS_PATH);
    }

    /**
     * Writes the decoded file to `$destination` as it arrives, without holding it in memory and without a size limit.
     *
     * A path is written to a temporary file of its own next to it (`.<name>.XXXXXX`, mode 0600 until complete) and
     * renamed over `$path` when the download is complete; on failure the temporary file is removed and `$path` is
     * untouched, so two downloads to one path at once do not share a file (the last rename wins). A stream is written
     * to as is and left open and unrewound; what it holds after a failure is the caller's to discard.
     *
     * @throws \InvalidArgumentException `$app` is not a positive 32-bit integer
     * @throws GeneralExportException the destination is a directory or not writable, or the rename failed
     * @throws HttpException
     * @throws TransportException
     */
    public function download(
        ExportKind $kind,
        int $app,
        ExportWindow $window,
        ExportFormat $format,
        string|StreamInterface $destination,
    ): void {
        $url = $this->fileUrl($kind, $app, $window, $format);
        if (\is_string($destination) && is_dir($destination)) {
            throw new GeneralExportException('The download destination is a directory.');
        }
        $body = $this->transport->get($url);
        if ($destination instanceof StreamInterface) {
            $this->copy($body, $destination);

            return;
        }
        $this->downloadToPath($body, $destination);
    }

    /**
     * `$marketProductLink` of a row with `ref=<referralCode>`; see {@see AffiliateLink::of()}.
     *
     * @throws \InvalidArgumentException
     */
    public function affiliateLink(string $marketProductLink, string $referralCode): string
    {
        return AffiliateLink::of($marketProductLink, $referralCode);
    }

    /**
     * @return list<PriceRow>
     */
    private function fetchPriceRows(string $url): array
    {
        $body = $this->transport->get($url);

        return ExportMapper::priceRows($body->read($this->options->maxBodyBytes));
    }

    /**
     * @param list<PriceRow> $rows
     *
     * @return \Generator<int, PriceRow>
     */
    private function yieldRows(array $rows): \Generator
    {
        yield from $rows;
    }

    private function rowSource(BodyReader $reader, ExportFormat $format, string $path): RowSource
    {
        if ($format === ExportFormat::Csv) {
            return new CsvRows($reader, $path);
        }

        return match ($this->options->jsonStreamDecoder->resolve()) {
            JsonStreamDecoder::YajlStream => new YajlStreamRows($reader, $path),
            JsonStreamDecoder::Simdjson => new BatchJsonRows($reader, $path, simdjson: true),
            JsonStreamDecoder::JsonMachine, JsonStreamDecoder::Auto => new JsonMachineRows($reader, $path),
        };
    }

    private function fetchMeta(string $metaUrl): ExportMeta
    {
        $body = $this->transport->get($metaUrl);

        return ExportMapper::meta($body->read($this->options->maxBodyBytes));
    }

    private function assertMaxAge(?int $maxAgeSeconds): void
    {
        if ($maxAgeSeconds !== null && $maxAgeSeconds < 0) {
            throw new \InvalidArgumentException('maxAgeSeconds must not be negative.');
        }
    }

    /**
     * @throws StaleExportException
     */
    private function assertFresh(string $fileUrl, ?int $maxAgeSeconds): void
    {
        $this->assertMaxAge($maxAgeSeconds);
        if ($maxAgeSeconds === null) {
            return;
        }
        $meta = $this->fetchMeta($fileUrl . '.meta.json');
        if ($this->clock->now()->getTimestamp() - $meta->dataAsOf->getTimestamp() > $maxAgeSeconds) {
            throw new StaleExportException($meta->dataAsOf, $maxAgeSeconds);
        }
    }

    /**
     * `{base}/v1/{kind}/{app}{window}.{format}` (SDK_SPEC 3.10).
     *
     * @throws \InvalidArgumentException
     */
    private function fileUrl(ExportKind $kind, int $app, ExportWindow $window, ExportFormat $format): string
    {
        if ($app < 1 || $app > self::MAX_APP_ID) {
            throw new \InvalidArgumentException('app must be a Steam app id: an integer from 1 to 2147483647.');
        }

        return \sprintf('%s/v1/%s/%d%s.%s', rtrim($this->options->baseUrl, '/'), $kind->value, $app, $window->value, $format->value);
    }

    private function downloadToPath(ExportBody $body, string $path): void
    {
        $temporary = $this->temporaryFileFor($path);
        try {
            try {
                $file = $this->streamFactory->createStreamFromFile($temporary, 'wb');
            } catch (\RuntimeException $e) {
                throw new GeneralExportException('Cannot open the download destination for writing.', 0, $e);
            }
            try {
                $this->copy($body, $file);
            } finally {
                $file->close();
            }
            // The file was created private (0600); a finished download gets the mode a new file would have.
            @chmod($temporary, 0666 & ~umask());
            if (!@rename($temporary, $path)) {
                throw new GeneralExportException('Cannot move the finished download into place.');
            }
        } catch (\Throwable $e) {
            @unlink($temporary);

            throw $e;
        }
    }

    /**
     * A new, empty, private file in the directory of `$path` (the same file system, so the final rename is atomic).
     * `tempnam()` quietly falls back to the system temp directory when the directory is missing or not writable: that
     * is a failure here.
     *
     * @throws GeneralExportException
     */
    private function temporaryFileFor(string $path): string
    {
        $directory = \dirname($path);
        $temporary = @tempnam($directory, '.' . substr(basename($path), 0, 40) . '.');
        if ($temporary === false) {
            throw new GeneralExportException('Cannot create a temporary file next to the download destination.');
        }
        if (realpath(\dirname($temporary)) !== realpath($directory)) {
            @unlink($temporary);

            throw new GeneralExportException('Cannot create a temporary file next to the download destination.');
        }

        return $temporary;
    }

    /**
     * @throws GeneralExportException the destination cannot be written
     */
    private function copy(ExportBody $body, StreamInterface $destination): void
    {
        if (!$destination->isWritable()) {
            throw new GeneralExportException('The download destination stream is not writable.');
        }
        $reader = $body->reader();
        while (($chunk = $reader->next()) !== null) {
            try {
                $this->writeAll($destination, $chunk);
            } catch (GeneralExportException $e) {
                $reader->close();

                throw $e;
            } catch (\RuntimeException $e) {
                $reader->close();

                throw new GeneralExportException('Writing the download failed.', 0, $e);
            }
        }
    }

    /**
     * A write may take less than it is given (a full disk, a socket): the rest is written again, and a write that takes
     * nothing fails instead of looping.
     *
     * @throws \RuntimeException the stream failed, or ({@see GeneralExportException}) made no progress
     */
    private function writeAll(StreamInterface $destination, string $chunk): void
    {
        $length = \strlen($chunk);
        for ($offset = 0; $offset < $length;) {
            $written = $destination->write($offset === 0 ? $chunk : substr($chunk, $offset));
            if ($written < 1) {
                throw new GeneralExportException('Writing the download made no progress.');
            }
            $offset += $written;
        }
    }
}
