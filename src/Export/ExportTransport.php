<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use WhiteMarket\Sdk\Auth\HeaderValue;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Transport\BodyReader;
use WhiteMarket\Sdk\Transport\ClientResponse;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\RandomSource;
use WhiteMarket\Sdk\Transport\RetryLoop;
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\Transport\SafeLogger;
use WhiteMarket\Sdk\Transport\Sleeper;
use WhiteMarket\Sdk\Transport\SystemClock;
use WhiteMarket\Sdk\Transport\SystemRandomSource;
use WhiteMarket\Sdk\Transport\SystemSleeper;

/**
 * GETs export files over a PSR-18 client (SDK_SPEC 1.3, 1.4, 8). No credentials travel here: the export is public.
 *
 * Every request asks for gzip; the body is decoded by {@see BodyReader}, so size limits count decoded bytes. A 200
 * gives an {@see ExportBody}, a 304 to a conditional request gives {@see NotModified}, any other status (206, 202 and 204 included) an
 * {@see HttpException} (the same hierarchy as the GraphQL transport). A network error, 429 and every 5xx are retried as
 * {@see RetryPolicy::shouldRetryRead()} says.
 *
 * Redirects are followed here, not by the HTTP client (Guzzle's PSR-18 `sendRequest()` forces `allow_redirects` off):
 * at most {@see MAX_REDIRECTS}, only to an https URL on the host and port of the base URL, so it works with any
 * injected client. Anything else is a {@see TransportException}.
 *
 * The conditional cache holds the validators (`ETag`, `Last-Modified`) of a URL, never the body (SDK_SPEC 3.10). A cache
 * that fails is not an error of the export: the request goes out without validators.
 *
 * @internal used by {@see ExportClient}
 */
final readonly class ExportTransport
{
    public const MAX_REDIRECTS = 3;

    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    private LoggerInterface $logger;

    private RetryLoop $retryLoop;

    private string $originHost;

    private int $originPort;

    public function __construct(
        private ClientInterface $client,
        private RequestFactoryInterface $requestFactory,
        private ExportOptions $options = new ExportOptions(),
        private ?CacheInterface $cache = null,
        private RetryPolicy $retryPolicy = new RetryPolicy(),
        LoggerInterface $logger = new NullLogger(),
        Clock $clock = new SystemClock(),
        Sleeper $sleeper = new SystemSleeper(),
        RandomSource $random = new SystemRandomSource(),
    ) {
        $this->logger = SafeLogger::wrap($logger);
        $this->retryLoop = new RetryLoop($retryPolicy, $clock, $sleeper, $random, $this->logger);
        // ExportOptions has checked the base URL (https origin, no credentials).
        [$this->originHost, $this->originPort] = $this->origin($options->baseUrl)
            ?? throw new \LogicException('ExportOptions accepted a base URL that is not an https origin.');
    }

    public function hasCache(): bool
    {
        return $this->cache instanceof CacheInterface;
    }

    /**
     * Keeps the validators of a 200 response for the next conditional request of the URL (only when it has one and a
     * cache is configured). Called once the caller has the data: validators of a body that failed to parse must not
     * make the next call answer {@see NotModified}.
     */
    public function remember(ExportBody $body): void
    {
        if (!$this->cache instanceof CacheInterface || ($body->etag === null && $body->lastModified === null)) {
            return;
        }
        try {
            $this->cache->set($this->cacheKey($body->url), ['etag' => $body->etag, 'lastModified' => $body->lastModified]);
        } catch (\Throwable $e) {
            $this->logger->warning('WhiteMarket export cache write failed', ['exception' => $e::class]);
        }
    }

    /**
     * @throws HttpException the final response had a status other than 200
     * @throws TransportException no response, a redirect that is not allowed, or an unreadable body
     */
    public function get(string $url): ExportBody
    {
        $result = $this->exchange($url, null);
        \assert($result instanceof ExportBody);

        return $result;
    }

    /**
     * A conditional GET: sends the validators remembered for the URL, if any; a 304 then gives {@see NotModified}.
     *
     * @throws HttpException the final response had a status other than 200
     * @throws TransportException no response, a redirect that is not allowed, or an unreadable body
     */
    public function getIfModified(string $url): ExportBody|NotModified
    {
        return $this->exchange($url, $this->recall($url));
    }

    /**
     * @param array{etag: ?string, lastModified: ?string}|null $validators sent as the conditional headers; a 304 is
     *                                                                     only expected when there are some
     */
    private function exchange(string $url, ?array $validators): ExportBody|NotModified
    {
        return $this->retryLoop->run(
            'export',
            [],
            [],
            static fn(): string => $url,
            fn(string $target): ClientResponse => $this->send($target, $validators),
            fn(int $attempt, ?int $status, ?int $retryAfter): bool => $this->retryPolicy->shouldRetryRead($attempt, $status, $retryAfter),
            function (ClientResponse $response) use ($url, $validators): ExportBody|NotModified|null {
                $status = $response->statusCode();
                // Only a plain 200 is the file: 206 is a range, 202 and 204 carry no complete file (docs/DEVIATIONS.md).
                if ($status === 200) {
                    return new ExportBody(
                        $response,
                        $url,
                        $this->validator($response, 'ETag'),
                        $this->validator($response, 'Last-Modified'),
                    );
                }
                if ($status === 304 && $validators !== null) {
                    $response->discard();

                    return new NotModified($url);
                }

                return null;
            },
            fn(ClientResponse $response, int $status, ?int $retryAfter): \Throwable => HttpException::fromStatus(
                $status,
                BodyReader::errorExcerpt($response, $this->options->maxBodyBytes),
                $retryAfter,
            ),
            fn(string $what, #[\SensitiveParameter] \Throwable $e): TransportException => $this->transportException($what, $e),
        );
    }

    /**
     * The client failure is sensitive: it holds the request, and as a trace argument would expose that.
     */
    private function transportException(string $what, #[\SensitiveParameter] \Throwable $e): TransportException
    {
        return new TransportException(\sprintf('WhiteMarket export %s (%s).', $what, $e::class));
    }

    /**
     * Follows at most {@see MAX_REDIRECTS} redirects to the origin of the base URL.
     *
     * @param array{etag: ?string, lastModified: ?string}|null $validators
     */
    private function send(string $url, ?array $validators): ClientResponse
    {
        $current = $url;
        for ($hop = 0; ; ++$hop) {
            $response = RetryLoop::sendVia($this->client, $this->request($current, $validators));
            $location = trim($response->headerLine('Location'));
            if (!\in_array($response->statusCode(), self::REDIRECT_STATUSES, true) || $location === '') {
                return $response;
            }
            $response->discard();
            if ($hop >= self::MAX_REDIRECTS) {
                throw new TransportException(\sprintf('The export redirected more than %d times.', self::MAX_REDIRECTS));
            }
            $current = $this->redirectTarget($location);
        }
    }

    /**
     * @param array{etag: ?string, lastModified: ?string}|null $validators
     */
    private function request(string $url, ?array $validators): RequestInterface
    {
        $request = $this->requestFactory->createRequest('GET', $url)
            ->withHeader('Accept-Encoding', 'gzip')
            ->withHeader('User-Agent', $this->options->userAgent());
        if ($validators !== null && $validators['etag'] !== null) {
            $request = $request->withHeader('If-None-Match', $validators['etag']);
        }
        if ($validators !== null && $validators['lastModified'] !== null) {
            $request = $request->withHeader('If-Modified-Since', $validators['lastModified']);
        }

        return $request;
    }

    /**
     * The URL a `Location` points to: an absolute path on the current origin or an https URL on the same host and
     * port. The value is not echoed in the error: it is server input.
     */
    private function redirectTarget(string $location): string
    {
        if (preg_match('/^[\x21-\x7E]+$/D', $location) !== 1) {
            throw new TransportException('The export redirected to an invalid location.');
        }
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            return \sprintf('https://%s%s%s', $this->originHost, $this->originPort === 443 ? '' : ':' . $this->originPort, $location);
        }
        $origin = str_starts_with(strtolower($location), 'https://') ? $this->origin($location) : null;
        if ($origin === null) {
            throw new TransportException('The export redirected to a location that is not an https URL.');
        }
        if ($origin !== [$this->originHost, $this->originPort]) {
            throw new TransportException('The export redirected to another host.');
        }

        return $location;
    }

    /**
     * Host (lower case) and port of an https URL without credentials, or null for anything else.
     *
     * @return array{string, int}|null
     */
    private function origin(string $url): ?array
    {
        $parts = parse_url($url);
        if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return [strtolower($parts['host']), $parts['port'] ?? 443];
    }

    /**
     * @return array{etag: ?string, lastModified: ?string}|null the validators for the URL, null when none are usable
     */
    private function recall(string $url): ?array
    {
        if (!$this->cache instanceof CacheInterface) {
            return null;
        }
        try {
            $entry = $this->cache->get($this->cacheKey($url));
        } catch (\Throwable $e) {
            $this->logger->warning('WhiteMarket export cache read failed', ['exception' => $e::class]);

            return null;
        }
        // Whatever another process or version left in the cache is checked like server input: it goes into headers.
        if (!\is_array($entry)) {
            return null;
        }
        $etag = $this->headerValueOrNull($entry['etag'] ?? null);
        $lastModified = $this->headerValueOrNull($entry['lastModified'] ?? null);

        return $etag === null && $lastModified === null ? null : ['etag' => $etag, 'lastModified' => $lastModified];
    }

    private function headerValueOrNull(mixed $value): ?string
    {
        return \is_string($value) && HeaderValue::isValid($value) ? $value : null;
    }

    private function validator(ClientResponse $response, string $name): ?string
    {
        return $this->headerValueOrNull($response->headerLine($name));
    }

    /**
     * PSR-16 keys are limited to `A-Za-z0-9_.`.
     */
    private function cacheKey(string $url): string
    {
        return 'whitemarket_export_' . hash('sha256', $url);
    }
}
