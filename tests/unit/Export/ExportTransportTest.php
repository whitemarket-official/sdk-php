<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\GeneralHttpException;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Export\ExportBody;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Export\ExportTransport;
use WhiteMarket\Sdk\Export\NotModified;
use WhiteMarket\Sdk\Tests\Support\ArrayCache;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\MemoryLogger;
use WhiteMarket\Sdk\Tests\Support\RecordingSleeper;
use WhiteMarket\Sdk\Tests\Support\TestExport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Transport\RetryLoop;
use WhiteMarket\Sdk\Version;

#[CoversClass(ExportTransport::class)]
#[CoversClass(ExportBody::class)]
#[CoversClass(NotModified::class)]
#[CoversClass(RetryLoop::class)]
final class ExportTransportTest extends TestCase
{
    private const URL = 'https://export.white.market/v1/prices/730.json';

    private RecordingSleeper $sleeper;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
    }

    #[Test]
    public function sendsAGetThatAsksForGzipWithTheSdkUserAgent(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'ok'));

        $body = $this->get($http, options: new ExportOptions(userAgentSuffix: 'my-bot/1.0'));

        self::assertInstanceOf(ExportBody::class, $body);
        $request = $http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame(self::URL, (string) $request->getUri());
        self::assertSame(
            ['Host' => ['export.white.market'], 'Accept-Encoding' => ['gzip'], 'User-Agent' => [Version::userAgent() . ' my-bot/1.0']],
            $request->getHeaders(),
        );
    }

    #[Test]
    public function decodesAGzipBodyAndCountsDecodedBytesAgainstTheLimit(): void
    {
        $plain = str_repeat('{"a":1}', 3000);
        $gzip = FakeHttpClient::status(200, (string) gzencode($plain), ['Content-Encoding' => 'gzip']);
        $body = $this->get(new FakeHttpClient($gzip));

        self::assertInstanceOf(ExportBody::class, $body);
        self::assertSame($plain, $body->read(\strlen($plain)));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function decodedSizes(): iterable
    {
        yield 'exactly the limit' => [100, true];
        yield 'one byte over the limit' => [101, false];
    }

    #[Test]
    #[DataProvider('decodedSizes')]
    public function boundsTheDecodedSizeNotTheCompressedOne(int $decodedBytes, bool $accepted): void
    {
        $gzip = FakeHttpClient::status(200, (string) gzencode(str_repeat('a', $decodedBytes)), ['Content-Encoding' => 'gzip']);
        $body = $this->get(new FakeHttpClient($gzip));
        self::assertInstanceOf(ExportBody::class, $body);

        if ($accepted) {
            self::assertSame($decodedBytes, \strlen($body->read(100)));
        } else {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage('maxBodyBytes');
            $body->read(100);
        }
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function undecodableBodies(): iterable
    {
        yield 'corrupt gzip' => ['not gzip at all', ['Content-Encoding' => 'gzip']];
        yield 'truncated gzip' => [substr((string) gzencode(str_repeat('x', 5000)), 0, 20), ['Content-Encoding' => 'gzip']];
        yield 'unsupported encoding' => ['data', ['Content-Encoding' => 'br']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('undecodableBodies')]
    public function failsOnABodyItCannotDecode(string $payload, array $headers): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, $payload, $headers));

        $this->expectException(TransportException::class);

        $body = $this->get($http);
        self::assertInstanceOf(ExportBody::class, $body);
        $body->read(1_000_000);
    }

    #[Test]
    public function streamsTheDecodedBodyInChunksWithoutALimit(): void
    {
        // Hex digests do not compress below 8 KiB, so the gzip body spans several reads.
        $plain = implode('', array_map(static fn(int $i): string => hash('sha256', (string) $i), range(1, 1000)));
        $body = $this->get(new FakeHttpClient(FakeHttpClient::status(200, (string) gzencode($plain), ['Content-Encoding' => 'gzip'])));
        self::assertInstanceOf(ExportBody::class, $body);

        $reader = $body->reader();
        $chunks = [];
        while (($chunk = $reader->next()) !== null) {
            $chunks[] = $chunk;
        }

        self::assertSame($plain, implode('', $chunks));
        self::assertGreaterThan(1, \count($chunks));
    }

    #[Test]
    public function sendsTheRememberedValidatorsOfAConditionalRequest(): void
    {
        $cache = new ArrayCache();
        $first = $this->get(
            new FakeHttpClient(FakeHttpClient::status(200, 'v1', ['ETag' => '"abc"', 'Last-Modified' => 'Thu, 29 Oct 2026 10:00:00 GMT'])),
            cache: $cache,
            conditional: true,
        );
        self::assertInstanceOf(ExportBody::class, $first);
        $this->transport(new FakeHttpClient(), cache: $cache)->remember($first);
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'v2'));

        $this->get($http, cache: $cache, conditional: true);

        $request = $http->lastRequest();
        self::assertSame(['"abc"'], $request->getHeader('If-None-Match'));
        self::assertSame(['Thu, 29 Oct 2026 10:00:00 GMT'], $request->getHeader('If-Modified-Since'));
    }

    #[Test]
    public function sendsOnlyTheValidatorTheServerGave(): void
    {
        $cache = new ArrayCache();
        $transport = $this->transport(new FakeHttpClient(FakeHttpClient::status(200, 'v1', ['Last-Modified' => 'Thu, 29 Oct 2026 10:00:00 GMT'])), cache: $cache);
        $first = $transport->getIfModified(self::URL);
        self::assertInstanceOf(ExportBody::class, $first);
        $transport->remember($first);
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'v2'));

        $this->get($http, cache: $cache, conditional: true);

        self::assertFalse($http->lastRequest()->hasHeader('If-None-Match'));
        self::assertSame(['Thu, 29 Oct 2026 10:00:00 GMT'], $http->lastRequest()->getHeader('If-Modified-Since'));
    }

    #[Test]
    public function doesNotSendValidatorsUnlessTheRequestIsConditional(): void
    {
        $cache = new ArrayCache();
        $transport = $this->transport(new FakeHttpClient(FakeHttpClient::status(200, 'v1', ['ETag' => '"abc"'])), cache: $cache);
        $first = $transport->getIfModified(self::URL);
        self::assertInstanceOf(ExportBody::class, $first);
        $transport->remember($first);
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'v2'));

        $this->get($http, cache: $cache, conditional: false);

        self::assertFalse($http->lastRequest()->hasHeader('If-None-Match'));
    }

    #[Test]
    public function answersNotModifiedTo304OfAConditionalRequest(): void
    {
        $cache = new ArrayCache();
        $this->remembered($cache, ['ETag' => '"abc"']);
        $http = new FakeHttpClient(FakeHttpClient::status(304));

        $result = $this->get($http, cache: $cache, conditional: true);

        self::assertInstanceOf(NotModified::class, $result);
        self::assertSame(self::URL, $result->url);
    }

    #[Test]
    public function treats304OfAnUnconditionalRequestAsAnHttpError(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(304));

        $e = TraceArguments::caught(fn(): ExportBody|NotModified => $this->get($http));

        self::assertInstanceOf(GeneralHttpException::class, $e);
        self::assertSame(304, $e->status);
    }

    #[Test]
    public function storesValidatorsOnlyNeverTheBody(): void
    {
        $cache = new ArrayCache();
        $this->remembered($cache, ['ETag' => '"abc"', 'Last-Modified' => 'Thu, 29 Oct 2026 10:00:00 GMT'], 'SECRET-BODY-MARKER');

        $entries = array_values($cache->entries());

        self::assertCount(1, $entries);
        self::assertSame(['etag' => '"abc"', 'lastModified' => 'Thu, 29 Oct 2026 10:00:00 GMT'], $entries[0][0]);
        self::assertStringNotContainsString('SECRET-BODY-MARKER', serialize($entries));
    }

    #[Test]
    public function storesNothingWhenTheServerSentNoValidator(): void
    {
        $cache = new ArrayCache();
        $this->remembered($cache, []);

        self::assertSame([], $cache->entries());
    }

    #[Test]
    public function rememberingWithoutACacheDoesNothing(): void
    {
        $transport = $this->transport(new FakeHttpClient(FakeHttpClient::status(200, 'v1', ['ETag' => '"abc"'])));
        $body = $transport->get(self::URL);

        $transport->remember($body);

        self::assertFalse($transport->hasCache());
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unusableCacheEntries(): iterable
    {
        yield 'not an array' => ['"abc"'];
        yield 'validators of the wrong type' => [['etag' => 5, 'lastModified' => ['x']]];
        yield 'header injection in the ETag' => [['etag' => "\"abc\"\r\nX-Injected: 1", 'lastModified' => null]];
        yield 'empty entry' => [[]];
    }

    #[Test]
    #[DataProvider('unusableCacheEntries')]
    public function ignoresACacheEntryItCannotUse(mixed $entry): void
    {
        $cache = new ArrayCache();
        $cache->set('whitemarket_export_' . hash('sha256', self::URL), $entry);
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'full'));

        $this->get($http, cache: $cache, conditional: true);

        self::assertSame(['Host', 'Accept-Encoding', 'User-Agent'], array_keys($http->lastRequest()->getHeaders()));
    }

    #[Test]
    public function aFailingCacheDoesNotFailTheExport(): void
    {
        $cache = self::createStub(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('synthetic cache outage'));
        $cache->method('set')->willThrowException(new \RuntimeException('synthetic cache outage'));
        $logger = new MemoryLogger();
        $transport = $this->transport(new FakeHttpClient(FakeHttpClient::status(200, 'full', ['ETag' => '"abc"'])), cache: $cache, logger: $logger);

        $body = $transport->getIfModified(self::URL);
        self::assertInstanceOf(ExportBody::class, $body);
        $transport->remember($body);

        self::assertSame('full', $body->read(100));
        self::assertSame(
            ['WhiteMarket export cache read failed', 'WhiteMarket export cache write failed'],
            array_column(array_filter($logger->records(), static fn(array $record): bool => $record['level'] === 'warning'), 'message'),
        );
    }

    /**
     * @return iterable<string, array{list<ResponseInterface|\Throwable|\Closure(RequestInterface): ClientExceptionInterface>, class-string<HttpException|TransportException>, int, list<float>}>
     */
    public static function failures(): iterable
    {
        $unavailable = static fn(): ResponseInterface => FakeHttpClient::status(503);
        yield '503 three times -> 3 attempts' => [[$unavailable(), $unavailable(), $unavailable()], ServiceUnavailableException::class, 3, [0.25, 0.5]];
        yield '500 -> retried' => [[FakeHttpClient::status(500), FakeHttpClient::status(500), FakeHttpClient::status(500)], GeneralServerException::class, 3, [0.25, 0.5]];
        yield '429 without Retry-After -> retried' => [[FakeHttpClient::status(429), FakeHttpClient::status(429), FakeHttpClient::status(429)], RateLimitException::class, 3, [0.25, 0.5]];
        yield '503 with Retry-After -> not retried' => [[FakeHttpClient::status(503, '', ['Retry-After' => '7'])], ServiceUnavailableException::class, 1, []];
        yield '429 with Retry-After -> not retried' => [[FakeHttpClient::status(429, '', ['Retry-After' => '7'])], RateLimitException::class, 1, []];
        yield '404 -> not retried' => [[FakeHttpClient::status(404)], GeneralHttpException::class, 1, []];
        yield '501 -> retried (every 5xx)' => [[FakeHttpClient::status(501), FakeHttpClient::status(501), FakeHttpClient::status(501)], GeneralServerException::class, 3, [0.25, 0.5]];
        yield '507 -> retried (every 5xx)' => [[FakeHttpClient::status(507), FakeHttpClient::status(507), FakeHttpClient::status(507)], GeneralServerException::class, 3, [0.25, 0.5]];
        yield '599 -> retried (upper bound of 5xx)' => [[FakeHttpClient::status(599), FakeHttpClient::status(599), FakeHttpClient::status(599)], GeneralServerException::class, 3, [0.25, 0.5]];
        yield '499 -> not retried' => [[FakeHttpClient::status(499)], GeneralHttpException::class, 1, []];
        yield '501 with Retry-After -> retried (only 429 and 503 honour it)' => [[FakeHttpClient::status(501, '', ['Retry-After' => '7']), FakeHttpClient::status(501, '', ['Retry-After' => '7']), FakeHttpClient::status(501, '', ['Retry-After' => '7'])], GeneralServerException::class, 3, [0.25, 0.5]];
        yield '206 -> not a download, not retried' => [[FakeHttpClient::status(206, 'part')], GeneralHttpException::class, 1, []];
        yield '202 -> not a download, not retried' => [[FakeHttpClient::status(202, 'accepted')], GeneralHttpException::class, 1, []];
        yield '204 -> not a download, not retried' => [[FakeHttpClient::status(204)], GeneralHttpException::class, 1, []];
        yield 'AuthenticationException thrown by the client -> like any non-network client failure, not retried' => [[new AuthenticationException(401, 'Bearer synthetic')], TransportException::class, 1, []];
        yield 'network error three times' => [[FakeHttpClient::networkErrorHoldingTheRequest(), FakeHttpClient::networkErrorHoldingTheRequest(), FakeHttpClient::networkErrorHoldingTheRequest()], TransportException::class, 3, [0.25, 0.5]];
        yield 'failure outside PSR-18 -> not retried' => [[new \LogicException('synthetic middleware failure')], TransportException::class, 1, []];
    }

    /**
     * @param list<ResponseInterface|\Throwable|\Closure(RequestInterface): ClientExceptionInterface> $queue
     * @param class-string<HttpException|TransportException> $expected
     * @param list<float> $delays
     */
    #[Test]
    #[DataProvider('failures')]
    public function retriesLikeAQueryAndMapsTheFinalFailure(array $queue, string $expected, int $requests, array $delays): void
    {
        $http = new FakeHttpClient(...$queue);

        $e = TraceArguments::caught(fn(): ExportBody|NotModified => $this->get($http));

        self::assertInstanceOf($expected, $e);
        self::assertNull($e->getPrevious());
        self::assertCount($requests, $http->requests());
        self::assertSame($delays, $this->sleeper->delays());
    }

    #[Test]
    public function keepsRetryAfterOfAnUnretriedResponse(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(503, '', ['Retry-After' => '7']));

        $e = TraceArguments::caught(fn(): ExportBody|NotModified => $this->get($http));

        self::assertInstanceOf(ServiceUnavailableException::class, $e);
        self::assertSame(7, $e->retryAfter);
    }

    #[Test]
    public function succeedsAfterATransientFailure(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::networkErrorHoldingTheRequest(), FakeHttpClient::status(502), FakeHttpClient::status(200, 'ok'));

        $body = $this->get($http);

        self::assertInstanceOf(ExportBody::class, $body);
        self::assertSame('ok', $body->read(10));
        self::assertCount(3, $http->requests());
    }

    #[Test]
    public function putsTheStartOfAnErrorBodyInTheException(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(404, 'no such export'));

        $e = TraceArguments::caught(fn(): ExportBody|NotModified => $this->get($http));

        self::assertInstanceOf(HttpException::class, $e);
        self::assertSame('no such export', $e->bodyExcerpt);
    }

    #[Test]
    public function logsAttemptsWithoutTheUrl(): void
    {
        $logger = new MemoryLogger();
        $http = new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::status(200, 'ok'));

        $this->get($http, logger: $logger);

        self::assertStringNotContainsString('export.white.market', $logger->dump());
        self::assertSame(['debug', 'info', 'debug'], array_column($logger->records(), 'level'));
    }

    #[Test]
    public function followsARedirectWithinTheOrigin(): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::status(302, '', ['Location' => '/v1/prices/other.json']),
            FakeHttpClient::status(301, '', ['Location' => 'https://EXPORT.white.market/v1/prices/final.json']),
            FakeHttpClient::status(200, 'final'),
        );

        $body = $this->get($http);

        self::assertInstanceOf(ExportBody::class, $body);
        self::assertSame('final', $body->read(10));
        self::assertSame(
            [self::URL, 'https://export.white.market/v1/prices/other.json', 'https://export.white.market/v1/prices/final.json'],
            array_map(static fn(RequestInterface $request): string => (string) $request->getUri(), $http->requests()),
        );
    }

    #[Test]
    public function keepsTheConditionalHeadersAcrossARedirect(): void
    {
        $cache = new ArrayCache();
        $this->remembered($cache, ['ETag' => '"abc"']);
        $http = new FakeHttpClient(FakeHttpClient::status(302, '', ['Location' => '/v1/prices/other.json']), FakeHttpClient::status(304));

        $result = $this->get($http, cache: $cache, conditional: true);

        self::assertInstanceOf(NotModified::class, $result);
        self::assertSame(['"abc"'], $http->lastRequest()->getHeader('If-None-Match'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedRedirects(): iterable
    {
        yield 'another host' => ['https://evil.example.test/v1/prices/730.json'];
        yield 'a subdomain of the host' => ['https://sub.export.white.market/x'];
        yield 'a lookalike host' => ['https://export.white.market.evil.example.test/x'];
        yield 'credentials in the URL' => ['https://user:pass@export.white.market/x'];
        yield 'another port' => ['https://export.white.market:8443/x'];
        yield 'plain http' => ['http://export.white.market/x'];
        yield 'protocol-relative URL' => ['//evil.example.test/x'];
        yield 'relative path without a slash' => ['x/y.json'];
        yield 'whitespace in the location' => ['/x y'];
    }

    #[Test]
    #[DataProvider('refusedRedirects')]
    public function refusesARedirectOutsideTheOrigin(string $location): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(302, '', ['Location' => $location]), FakeHttpClient::status(200, 'must not be requested'));

        $e = TraceArguments::caught(fn(): ExportBody|NotModified => $this->get($http));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertCount(1, $http->requests());
        self::assertStringNotContainsString('evil', $e->getMessage());
        self::assertStringStartsWith('The export redirected', $e->getMessage(), 'the SDK refusal, not the "request failed" of a client failure');
    }

    /**
     * @return iterable<string, array{string, string}> the Location, the exact refusal message
     */
    public static function refusalMessages(): iterable
    {
        $notHttps = 'The export redirected to a location that is not an https URL.';
        yield 'plain http' => ['http://export.white.market/x', $notHttps];
        yield 'relative path without a slash' => ['x/y.json', $notHttps];
        yield 'protocol-relative URL' => ['//export.white.market/x', $notHttps];
        yield 'user and password on the same host' => ['https://user:pass@export.white.market/x', $notHttps];
        yield 'user only on the same host' => ['https://user@export.white.market/x', $notHttps];
        yield 'empty user and a password on the same host' => ['https://:pass@export.white.market/x', $notHttps];
        yield 'upper-case scheme is not accepted (pinned)' => ['HTTPS://export.white.market/x', $notHttps];
        yield 'another host' => ['https://evil.example.test/x', 'The export redirected to another host.'];
        yield 'another port of the same host' => ['https://export.white.market:8443/x', 'The export redirected to another host.'];
        yield 'a port next to the default one' => ['https://export.white.market:444/x', 'The export redirected to another host.'];
        yield 'whitespace in the location' => ['/x y', 'The export redirected to an invalid location.'];
    }

    #[Test]
    #[DataProvider('refusalMessages')]
    public function refusesARedirectWithTheExactReasonAndSendsNothingToIt(string $location, string $message): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(302, '', ['Location' => $location]), FakeHttpClient::status(200, 'must not be requested'));

        $e = TraceArguments::caught(fn(): ExportBody|NotModified => $this->get($http));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame($message, $e->getMessage());
        self::assertCount(1, $http->requests());
    }

    #[Test]
    public function followsALocationWithTheExplicitDefaultPortOfTheOrigin(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(302, '', ['Location' => 'https://export.white.market:443/x']), FakeHttpClient::status(200, 'ok'));

        self::assertInstanceOf(ExportBody::class, $this->get($http));
        self::assertCount(2, $http->requests());
    }

    /**
     * @return iterable<string, array{string, string, string}> the base URL, the request URL, the URL a relative Location must be sent to
     */
    public static function relativeRedirectOrigins(): iterable
    {
        yield 'default port is left out' => ['https://export.example.test', 'https://export.example.test/v1/a.json', 'https://export.example.test/v1/x'];
        yield 'explicit default port is left out' => ['https://export.example.test:443', 'https://export.example.test/v1/a.json', 'https://export.example.test/v1/x'];
        yield 'another port is kept' => ['https://export.example.test:8443', 'https://export.example.test:8443/v1/a.json', 'https://export.example.test:8443/v1/x'];
        yield 'port next to the default one is kept' => ['https://export.example.test:444', 'https://export.example.test:444/v1/a.json', 'https://export.example.test:444/v1/x'];
        yield 'host is lower-cased' => ['https://EXPORT.example.test', 'https://export.example.test/v1/a.json', 'https://export.example.test/v1/x'];
    }

    /**
     * The URL string handed to the request factory is checked, not the sent URI: a PSR-7 URI drops the default port by itself.
     */
    #[Test]
    #[DataProvider('relativeRedirectOrigins')]
    public function buildsTheTargetOfARelativeLocationFromTheOrigin(string $baseUrl, string $url, string $expected): void
    {
        $factory = new class implements RequestFactoryInterface {
            /** @var \ArrayObject<int, string> */
            public readonly \ArrayObject $urls;

            public function __construct()
            {
                $this->urls = new \ArrayObject();
            }

            public function createRequest(string $method, $uri): RequestInterface
            {
                $this->urls->append((string) $uri);

                return (new HttpFactory())->createRequest($method, $uri);
            }
        };
        $http = new FakeHttpClient(FakeHttpClient::status(302, '', ['Location' => '/v1/x']), FakeHttpClient::status(200, 'ok'));
        $transport = new ExportTransport($http, $factory, new ExportOptions($baseUrl), sleeper: $this->sleeper);

        $transport->get($url);

        self::assertSame([$url, $expected], $factory->urls->getArrayCopy());
    }

    #[Test]
    public function followsAtMostThreeRedirects(): void
    {
        $redirect = static fn(): ResponseInterface => FakeHttpClient::status(302, '', ['Location' => '/next']);
        $ok = new FakeHttpClient($redirect(), $redirect(), $redirect(), FakeHttpClient::status(200, 'ok'));
        $tooMany = new FakeHttpClient($redirect(), $redirect(), $redirect(), $redirect(), FakeHttpClient::status(200, 'ok'));

        self::assertInstanceOf(ExportBody::class, $this->get($ok));
        $e = TraceArguments::caught(fn(): ExportBody|NotModified => $this->get($tooMany));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('The export redirected more than 3 times.', $e->getMessage());
        self::assertCount(4, $tooMany->requests());
    }

    #[Test]
    public function aRedirectWithoutALocationIsAnHttpError(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(302));

        $e = TraceArguments::caught(fn(): ExportBody|NotModified => $this->get($http));

        self::assertInstanceOf(GeneralHttpException::class, $e);
        self::assertSame(302, $e->status);
    }

    #[Test]
    public function followsRedirectsOnTheConfiguredPort(): void
    {
        $options = new ExportOptions('https://export.example.test:8443');
        $http = new FakeHttpClient(FakeHttpClient::status(302, '', ['Location' => '/v1/x']), FakeHttpClient::status(200, 'ok'));

        $this->transport($http, options: $options)->get('https://export.example.test:8443/v1/prices/730.json');

        self::assertSame('https://export.example.test:8443/v1/x', (string) $http->lastRequest()->getUri());
    }

    /**
     * @param array<string, string> $headers
     */
    private function remembered(ArrayCache $cache, array $headers, string $payload = 'v1'): void
    {
        $transport = $this->transport(new FakeHttpClient(FakeHttpClient::status(200, $payload, $headers)), cache: $cache);
        $body = $transport->getIfModified(self::URL);
        self::assertInstanceOf(ExportBody::class, $body);
        $transport->remember($body);
    }

    private function get(
        FakeHttpClient $http,
        ExportOptions $options = new ExportOptions(),
        ?CacheInterface $cache = null,
        bool $conditional = false,
        ?MemoryLogger $logger = null,
    ): ExportBody|NotModified {
        $transport = $this->transport($http, $options, $cache, logger: $logger);

        return $conditional ? $transport->getIfModified(self::URL) : $transport->get(self::URL);
    }

    private function transport(
        FakeHttpClient $http,
        ExportOptions $options = new ExportOptions(),
        ?CacheInterface $cache = null,
        ?MemoryLogger $logger = null,
    ): ExportTransport {
        return TestExport::transport($http, $options, $cache, sleeper: $this->sleeper, logger: $logger ?? new NullLogger());
    }
}
