<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Errors\TransportException;

/**
 * Reads a response body in small chunks and decompresses it when the response says `Content-Encoding: gzip`
 * (SDK_SPEC 1.3). The one place that reads bodies: {@see HttpTransport} collects the chunks up to a size limit, the
 * export transport writes them to a file or parses them without holding the whole body. A pull reader, not a
 * generator: the failures it throws are checked exceptions, which a yielding method may not throw.
 *
 * The size limit of the caller counts decompressed bytes whatever client delivered the response, and one decompression
 * step is bounded: the inflater is fed {@see COMPRESSED_SLICE_BYTES} compressed bytes at a time and deflate cannot
 * expand a byte by more than 1032 (zlib's documented best ratio), so a step yields at most {@see MAX_STEP_BYTES}
 * decoded bytes. A caller that checks its limit before it appends a chunk holds at most its limit, never limit plus a
 * step.
 *
 * A body with a `Content-Length` (identity or gzip: raw bytes count) must have exactly that many bytes: a client that
 * streams the body (Guzzle's stream handler) does not check, and a cut connection would otherwise pass as a complete
 * file. A gzip body must have the same count of raw (compressed) bytes and reach its end marker. Not detectable through PSR-7: an identity chunked body cut
 * before its final chunk (docs/DEVIATIONS.md).
 *
 * @internal
 */
final class BodyReader
{
    /** The best compression ratio of deflate (zlib `inflate` documentation, "1032:1"). */
    private const MAX_EXPANSION = 1032;

    /** Compressed bytes handed to the inflater at once. */
    private const COMPRESSED_SLICE_BYTES = 1024;

    /** The most decoded bytes one decompression step can produce: 1024 * 1032 = 1056768 (about 1 MiB). */
    public const MAX_STEP_BYTES = self::COMPRESSED_SLICE_BYTES * self::MAX_EXPANSION;

    /** Bytes asked of the body stream per read. */
    private const CHUNK_BYTES = 8192;

    /** An error body is read up to the size {@see \WhiteMarket\Sdk\Errors\BodyRedactor} still redacts by structure. */
    private const ERROR_BODY_BYTES = 1024 * 1024;

    /** Compressed bytes read from the stream and not yet inflated. */
    private string $pending = '';

    private bool $closed = false;

    private bool $reachedStreamEnd = false;

    /** Compressed bytes fed to the inflater. */
    private int $fed = 0;

    /** Bytes read from the stream, to compare with `Content-Length`. */
    private int $rawBytes = 0;

    private function __construct(
        private readonly ClientStream $stream,
        private readonly ?\InflateContext $inflate,
        private readonly bool $lenient,
        private readonly bool $rejectTrailingData,
        private readonly ?int $announcedLength,
    ) {}

    /**
     * A reader of the response body. A corrupt or truncated body fails {@see next()} with a {@see TransportException}
     * (a truncated gzip stream decodes without an error but never reaches its end marker; an identity body shorter or
     * longer than its `Content-Length`), unless `$lenient`: then the chunks decoded before the failure are all there is
     * (an error body whose excerpt is wanted). An unsupported `Content-Encoding` and a failed read fail in both modes.
     *
     * @param bool $rejectTrailingData fail when data follows the end of the gzip stream (exports: a body cut to its
     *                                 first member must not pass as complete); by default the rest is ignored
     *
     * @throws TransportException unsupported `Content-Encoding`, or (identity body, not lenient) an invalid `Content-Length`
     */
    public static function open(ResponseInterface|ClientResponse $response, bool $lenient = false, bool $rejectTrailingData = false): self
    {
        $response = $response instanceof ClientResponse ? $response : new ClientResponse($response);
        $inflate = self::inflater($response);
        $announced = $lenient ? null : self::announcedLength($response);

        return new self($response->body(), $inflate, $lenient, $rejectTrailingData && !$lenient, $announced);
    }

    /**
     * The whole decoded body, at most `$limit` bytes: a body over it fails, naming `$limitName` in the message, before
     * the chunk that crosses the limit is kept. The body stream is closed.
     *
     * @throws TransportException
     */
    public static function readAll(ResponseInterface|ClientResponse $response, int $limit, string $limitName, bool $rejectTrailingData = false): string
    {
        $reader = self::open($response, rejectTrailingData: $rejectTrailingData);
        $body = '';
        try {
            while (($chunk = $reader->next()) !== null) {
                if (\strlen($body) + \strlen($chunk) > $limit) {
                    throw new TransportException(\sprintf('The response body exceeds %d bytes (%s).', $limit, $limitName));
                }
                $body .= $chunk;
            }
        } finally {
            $reader->close();
        }

        return $body;
    }

    /**
     * The start of an error body for an exception's excerpt: at most `$maxBytes` (and 1 MiB), cut there instead of
     * failing, and whatever decoded before a gzip failure is kept. The body stream is closed.
     *
     * @throws TransportException unsupported `Content-Encoding`, or a failed read
     */
    public static function errorExcerpt(ResponseInterface|ClientResponse $response, int $maxBytes): string
    {
        $limit = min($maxBytes, self::ERROR_BODY_BYTES);
        $reader = self::open($response, lenient: true);
        $body = '';
        try {
            while (($chunk = $reader->next()) !== null) {
                $room = $limit - \strlen($body);
                if (\strlen($chunk) > $room) {
                    return $body . substr($chunk, 0, $room);
                }
                $body .= $chunk;
            }
        } finally {
            $reader->close();
        }

        return $body;
    }

    /**
     * The next decoded chunk, null at the end of the body. The stream is closed at the end and on a failure.
     *
     * @throws TransportException
     */
    public function next(): ?string
    {
        try {
            while (!$this->closed) {
                $chunk = $this->decodedRead();
                if ($chunk === null) {
                    $this->close();

                    return null;
                }
                if ($chunk !== '') {
                    return $chunk;
                }
            }

            return null;
        } catch (\Throwable $e) {
            $this->close();

            throw $e;
        }
    }

    /**
     * Closes the stream once: a close that failed is not tried again, so its failure is not chained to itself.
     *
     * @throws TransportException the client's stream failed to close
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->stream->close();
    }

    /**
     * One step of reading, decoded: null at the end (a lenient reader also ends at a gzip failure), an empty string
     * when the step produced nothing.
     *
     * @throws TransportException
     */
    private function decodedRead(): ?string
    {
        if (!$this->inflate instanceof \InflateContext) {
            return $this->identityRead();
        }
        if ($this->reachedStreamEnd) {
            return $this->afterStreamEnd();
        }
        if ($this->pending === '') {
            if ($this->eof()) {
                // A truncated stream decodes without an error but never reaches its end marker.
                return $this->lenient ? null : throw new TransportException('The response body is not valid gzip.');
            }
            $this->pending = $this->read();

            return '';
        }

        return $this->inflateSlice($this->inflate);
    }

    /**
     * @throws TransportException
     */
    private function identityRead(): ?string
    {
        if (!$this->eof()) {
            return $this->read();
        }
        $this->assertNotShort();

        return null;
    }

    /**
     * @throws TransportException
     */
    private function assertNotShort(): void
    {
        if ($this->announcedLength !== null && $this->rawBytes < $this->announcedLength) {
            throw new TransportException('The response body is shorter than Content-Length.');
        }
    }

    /**
     * @throws TransportException
     */
    private function inflateSlice(\InflateContext $inflate): ?string
    {
        $slice = substr($this->pending, 0, self::COMPRESSED_SLICE_BYTES);
        $this->pending = substr($this->pending, self::COMPRESSED_SLICE_BYTES);
        $this->fed += \strlen($slice);
        // A corrupt stream makes inflate_add() warn and return false.
        $decoded = @inflate_add($inflate, $slice, \ZLIB_SYNC_FLUSH);
        if ($decoded === false) {
            return $this->lenient ? null : throw new TransportException('The response body is not valid gzip.');
        }
        if (inflate_get_status($inflate) === \ZLIB_STREAM_END) {
            $this->reachedStreamEnd = true;
            if ($this->rejectTrailingData && inflate_get_read_len($inflate) < $this->fed) {
                throw $this->trailingData();
            }
        }

        return $decoded;
    }

    /**
     * Past the end of the first gzip member: the rest is ignored (read and dropped when a `Content-Length` is there to
     * check), or, for a reader that rejects trailing data, any further byte fails.
     *
     * @throws TransportException
     */
    private function afterStreamEnd(): ?string
    {
        if (!$this->rejectTrailingData && $this->announcedLength === null) {
            return null;
        }
        if ($this->pending !== '' && $this->rejectTrailingData) {
            throw $this->trailingData();
        }
        $this->pending = '';
        if ($this->eof()) {
            $this->assertNotShort();

            return null;
        }
        // The rest is ignored (or, for a reader that rejects trailing data, an error), but an announced length is
        // checked against every raw byte: read() fails when the body is longer.
        if ($this->read() !== '' && $this->rejectTrailingData) {
            throw $this->trailingData();
        }

        return '';
    }

    private function trailingData(): TransportException
    {
        return new TransportException('The response body has data after the end of the gzip stream.');
    }

    /**
     * The gzip decompressor the response needs, or null for an uncompressed body.
     *
     * @throws TransportException
     */
    private static function inflater(ClientResponse $response): ?\InflateContext
    {
        $encoding = strtolower(trim($response->headerLine('Content-Encoding')));
        $inflate = match ($encoding) {
            '', 'identity' => null,
            'gzip', 'x-gzip' => inflate_init(\ZLIB_ENCODING_GZIP),
            default => throw new TransportException(\sprintf('Unsupported response Content-Encoding "%s".', substr($encoding, 0, 32))),
        };
        if ($inflate === false) {
            throw new TransportException('Cannot start gzip decompression.');
        }

        return $inflate;
    }

    /**
     * The announced `Content-Length`; null when the header is absent (nothing to check). One value, or several that are
     * all the same plain number (RFC 9110 8.6 allows identical repeats), give that number.
     *
     * @throws TransportException any other value (`+1000`, `1000, 999`, letters, an empty value): the length cannot be
     *                            trusted, so the body cannot be checked against it
     */
    private static function announcedLength(ClientResponse $response): ?int
    {
        if (!$response->hasHeader('Content-Length')) {
            return null;
        }
        $lengths = array_unique(array_map(trim(...), explode(',', $response->headerLine('Content-Length'))));
        if (\count($lengths) !== 1 || preg_match('/^\d{1,18}$/D', $lengths[array_key_first($lengths)]) !== 1) {
            throw new TransportException('The response has an invalid Content-Length.');
        }

        return (int) $lengths[array_key_first($lengths)];
    }

    /**
     * @throws TransportException
     */
    private function eof(): bool
    {
        return $this->stream->eof();
    }

    /**
     * @throws TransportException
     */
    private function read(): string
    {
        $chunk = $this->stream->read(self::CHUNK_BYTES);
        $this->rawBytes += \strlen($chunk);
        if ($this->announcedLength !== null && $this->rawBytes > $this->announcedLength) {
            throw new TransportException('The response body is longer than Content-Length.');
        }

        return $chunk;
    }
}
