<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live\Sse;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\GeneralHttpException;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\Sse\SseConnection;
use WhiteMarket\Sdk\Tests\Support\ChunkedStream;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\FrozenClock;
use WhiteMarket\Sdk\Tests\Support\HostileStream;
use WhiteMarket\Sdk\Tests\Support\ScriptedStream;
use WhiteMarket\Sdk\Tests\Support\SyntheticJwt;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;

#[CoversClass(SseConnection::class)]
final class SseConnectionTest extends TestCase
{
    private const CONNECT = "data: {\"connect\":{\"client\":\"c1\",\"ping\":30,\"pong\":false,\"ttl\":86400,\"expires\":true,\"subs\":{\"broadcast\":{}}}}\n\n";

    private const EVENT_A = "data: {\"channel\":\"ch\",\"pub\":{\"data\":{\"message\":\"a\"}}}\n\n";

    private const EVENT_B = "data: {\"channel\":\"ch\",\"pub\":{\"data\":{\"message\":\"b\"}}}\n\n";

    private string $jwt;

    protected function setUp(): void
    {
        $this->jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-subject']);
    }

    private function connection(FakeHttpClient $http, ?LiveOptions $options = null, string $now = '2026-01-01T00:00:00+00:00'): SseConnection
    {
        return new SseConnection(
            $http,
            GuzzleClientFactory::messageFactory(),
            'https://api.example.test/',
            $options ?? new LiveOptions(),
            new FrozenClock($now),
        );
    }

    private function ok(StreamInterface|string $body, int $chunk = 4096): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/event-stream'], \is_string($body) ? ChunkedStream::of($body, $chunk) : $body);
    }

    private function timeout(float $seconds = 30.0): float
    {
        return $seconds;
    }

    /**
     * @param list<string> $channels
     */
    private function open(SseConnection $connection, array $channels = ['market_products_updates']): void
    {
        $connection->open(new Secret($this->jwt), $channels, $this->timeout());
    }

    #[Test]
    public function sendsOneGetWithTheTokenAndChannelsInTheQuery(): void
    {
        $http = new FakeHttpClient($this->ok(self::CONNECT));

        $this->open($this->connection($http), ['market_products_updates', 'other']);

        $request = $http->lastRequest();
        self::assertSame('GET', $request->getMethod());
        self::assertSame('api.example.test', $request->getUri()->getHost());
        self::assertSame('/sse_endpoint', $request->getUri()->getPath());
        self::assertSame(
            \sprintf('{"token":"%s","subs":{"market_products_updates":{},"other":{}}}', $this->jwt),
            $this->connectParameter($request),
        );
        self::assertSame('text/event-stream', $request->getHeaderLine('Accept'));
        self::assertSame('identity', $request->getHeaderLine('Accept-Encoding'));
        self::assertSame((new LiveOptions())->userAgent(), $request->getHeaderLine('User-Agent'));
        self::assertCount(1, $http->requests());
    }

    #[Test]
    public function sendsEmptySubsAsAnObject(): void
    {
        $http = new FakeHttpClient($this->ok(self::CONNECT));

        $this->open($this->connection($http), []);

        self::assertStringEndsWith('"subs":{}}', $this->connectParameter($http->lastRequest()));
    }

    #[Test]
    public function usesTheConfiguredPath(): void
    {
        $http = new FakeHttpClient($this->ok(self::CONNECT));

        $this->open($this->connection($http, new LiveOptions(ssePath: '/custom/sse')));

        self::assertSame('/custom/sse', $http->lastRequest()->getUri()->getPath());
    }

    #[Test]
    public function returnsTheConnectInfoOfTheFirstEvent(): void
    {
        $connection = $this->connection(new FakeHttpClient($this->ok(self::CONNECT)));

        $info = $connection->open(new Secret($this->jwt), [], $this->timeout());

        self::assertSame(30, $info->ping);
        self::assertFalse($info->pong);
        self::assertSame(86400, $info->ttl);
        self::assertSame(86400, $info->ttl);
        self::assertSame(['broadcast'], $info->subs);
    }

    /**
     * @return iterable<string, array{int, array<string, string>, class-string<HttpException>, ?int}>
     */
    public static function statuses(): iterable
    {
        yield '401 -> authentication' => [401, [], AuthenticationException::class, null];
        yield '429 with Retry-After seconds' => [429, ['Retry-After' => '7'], RateLimitException::class, 7];
        yield '429 with Retry-After date' => [429, ['Retry-After' => 'Thu, 01 Jan 2026 00:00:30 GMT'], RateLimitException::class, 30];
        yield '429 without Retry-After' => [429, [], RateLimitException::class, null];
        yield '503 with Retry-After' => [503, ['Retry-After' => '12'], ServiceUnavailableException::class, 12];
        yield '500 -> server' => [500, [], GeneralServerException::class, null];
        yield '404 -> general http' => [404, [], GeneralHttpException::class, null];
        yield '301 is not a stream' => [301, [], GeneralHttpException::class, null];
    }

    /**
     * @param array<string, string> $headers
     * @param class-string<HttpException> $class
     */
    #[Test]
    #[DataProvider('statuses')]
    public function mapsANon2xxStatusToItsHttpException(int $status, array $headers, string $class, ?int $retryAfter): void
    {
        $body = ChunkedStream::of('{"error":"synthetic"}', 4096);
        $connection = $this->connection(new FakeHttpClient(new Response($status, $headers, $body)));

        try {
            $this->open($connection);
            self::fail('expected an HttpException');
        } catch (HttpException $e) {
            self::assertInstanceOf($class, $e);
            self::assertSame($status, $e->status);
            self::assertSame($retryAfter, $e->retryAfter);
            self::assertStringContainsString('synthetic', $e->bodyExcerpt);
            self::assertTrue($body->wasClosed());
            self::assertStringNotContainsString($this->jwt, $e->getMessage());
        }
    }

    #[Test]
    public function readsOnlyABoundedExcerptOfAnErrorBody(): void
    {
        $body = new ScriptedStream(array_fill(0, 25, str_repeat('x', 4096)));
        $connection = $this->connection(new FakeHttpClient(new Response(500, [], $body)));

        try {
            $this->open($connection);
            self::fail('expected an HttpException');
        } catch (HttpException $e) {
            self::assertLessThanOrEqual(HttpException::BODY_EXCERPT_BYTES, \strlen($e->bodyExcerpt));
            self::assertSame(1, $body->readCalls());
        }
    }

    #[Test]
    public function stillReportsTheStatusWhenTheErrorBodyCannotBeRead(): void
    {
        $body = new HostileStream(ChunkedStream::of('x', 1), 'read', new \RuntimeException('boom'));
        $connection = $this->connection(new FakeHttpClient(new Response(503, ['Retry-After' => '5'], $body)));

        $this->expectException(ServiceUnavailableException::class);
        $this->open($connection);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function encodings(): iterable
    {
        yield 'gzip' => ['gzip'];
        yield 'GZIP uppercase' => ['GZIP'];
        yield 'br' => ['br'];
        yield 'identity then gzip' => ['identity, gzip'];
    }

    #[Test]
    #[DataProvider('encodings')]
    public function rejectsACompressedStreamWithoutEchoingTheHeader(string $encoding): void
    {
        $body = ChunkedStream::of(self::CONNECT, 4096);
        $connection = $this->connection(new FakeHttpClient(new Response(200, ['Content-Encoding' => $encoding], $body)));

        try {
            $this->open($connection);
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertStringNotContainsStringIgnoringCase($encoding, $e->getMessage());
            self::assertTrue($body->wasClosed());
        }
    }

    #[Test]
    public function acceptsAnIdentityContentEncoding(): void
    {
        $http = new FakeHttpClient(new Response(200, ['Content-Encoding' => 'Identity'], ChunkedStream::of(self::CONNECT, 4096)));

        $info = $this->connection($http)->open(new Secret($this->jwt), [], $this->timeout());

        self::assertSame(30, $info->ping);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAConnectReply(): iterable
    {
        yield 'a publication' => [self::EVENT_A];
        yield 'a ping' => ["data: {}\n\n"];
        yield 'not json' => ["data: nonsense\n\n"];
        yield 'a malformed connect' => ["data: {\"connect\":{\"pong\":\"yes\"}}\n\n"];
        yield 'comments only, then the end' => [": hello\n\n"];
        yield 'an empty body' => [''];
    }

    #[Test]
    #[DataProvider('notAConnectReply')]
    public function failsWhenTheFirstMessageIsNotAConnectReply(string $body): void
    {
        $stream = ChunkedStream::of($body, 4096);
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));

        $this->expectException(TransportException::class);
        try {
            $this->open($connection);
        } finally {
            self::assertTrue($stream->wasClosed());
        }
    }

    #[Test]
    public function failsWhenNoConnectReplyComesBeforeTheDeadline(): void
    {
        $stream = ScriptedStream::expiringSlices([]);
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('no connect reply');
        try {
            $connection->open(new Secret($this->jwt), [], $this->timeout(0.05));
        } finally {
            self::assertTrue($stream->wasClosed());
        }
    }

    #[Test]
    public function waitsForTheConnectReplyAcrossExpiredSlices(): void
    {
        $stream = ScriptedStream::expiringSlices([
            ScriptedStream::sliceExpired(),
            ScriptedStream::sliceExpired(),
            self::CONNECT,
        ]);

        $info = $this->connection(new FakeHttpClient($this->ok($stream)))->open(new Secret($this->jwt), [], $this->timeout());

        self::assertSame(30, $info->ping);
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function chunkSizes(): iterable
    {
        yield '1 byte' => [1];
        yield '2 bytes (splits CRLF)' => [2];
        yield '7 bytes' => [7];
        yield '13 bytes' => [13];
        yield 'whole body' => [4096];
    }

    #[Test]
    #[DataProvider('chunkSizes')]
    public function framesEventsAcrossChunkBoundaries(int $chunk): void
    {
        $body = str_replace("\n", "\r\n", self::CONNECT . self::EVENT_A) . self::EVENT_B;
        $connection = $this->connection(new FakeHttpClient($this->ok($body, $chunk)));

        $this->open($connection);

        self::assertSame('{"channel":"ch","pub":{"data":{"message":"a"}}}', $connection->read($this->timeout()));
        self::assertSame('{"channel":"ch","pub":{"data":{"message":"b"}}}', $connection->read($this->timeout()));
    }

    #[Test]
    public function returnsAQueuedEventEvenWhenTheDeadlineHasPassed(): void
    {
        $connection = $this->connection(new FakeHttpClient($this->ok(self::CONNECT . self::EVENT_A . self::EVENT_B)));
        $this->open($connection);

        self::assertNotNull($connection->read($this->timeout()));
        self::assertNotNull($connection->read(-10.0));
    }

    #[Test]
    public function endOfStreamAfterCompleteEventsIsABrokenConnection(): void
    {
        $connection = $this->connection(new FakeHttpClient($this->ok(self::CONNECT . self::EVENT_A)));
        $this->open($connection);
        self::assertNotNull($connection->read($this->timeout()));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('ended');
        $connection->read($this->timeout());
    }

    #[Test]
    public function endOfStreamInTheMiddleOfAnEventDropsItAndFails(): void
    {
        $connection = $this->connection(new FakeHttpClient($this->ok(self::CONNECT . substr(self::EVENT_A, 0, 25))));
        $this->open($connection);

        $this->expectException(TransportException::class);
        $connection->read($this->timeout());
    }

    #[Test]
    public function returnsNullWhenTheDeadlinePassesOverExpiredSlices(): void
    {
        $stream = ScriptedStream::expiringSlices([self::CONNECT, ScriptedStream::sliceExpired(), substr(self::EVENT_A, 0, 20)]);
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));
        $this->open($connection);

        self::assertNull($connection->read($this->timeout(0.05)));
    }

    #[Test]
    public function aPartialEventKeptAcrossAnExpiredSliceCompletesLater(): void
    {
        $stream = ScriptedStream::expiringSlices([
            self::CONNECT,
            substr(self::EVENT_A, 0, 20),
            ScriptedStream::sliceExpired(),
            substr(self::EVENT_A, 20),
        ]);
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));
        $this->open($connection);

        self::assertSame('{"channel":"ch","pub":{"data":{"message":"a"}}}', $connection->read($this->timeout()));
    }

    #[Test]
    public function aFailedReadThatIsNotAnExpiredSliceIsABrokenConnection(): void
    {
        $stream = new ScriptedStream([self::CONNECT, ScriptedStream::sliceExpired()], ['timed_out' => false], true);
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));
        $this->open($connection);

        $this->expectException(TransportException::class);
        $connection->read($this->timeout());
    }

    #[Test]
    public function anExpiredSliceAtEndOfStreamIsABrokenConnection(): void
    {
        $stream = ScriptedStream::expiringSlices([self::CONNECT], true);
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));
        $this->open($connection);

        $this->expectException(TransportException::class);
        $connection->read($this->timeout());
    }

    #[Test]
    public function anEmptyReadAtEndOfStreamIsABrokenConnectionEvenWithTheTimedOutFlag(): void
    {
        // Mutation guard: '' + eof must not be treated as "keep waiting" whatever the metadata says.
        $stream = ScriptedStream::expiringSlices([self::CONNECT, ''], true);
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));
        $this->open($connection);

        $this->expectException(TransportException::class);
        $connection->read($this->timeout());
    }

    #[Test]
    public function failsWhenAnEventExceedsTheSizeCap(): void
    {
        $big = 'data: ' . str_repeat('x', 2000) . "\n\n";
        $connection = $this->connection(new FakeHttpClient($this->ok(self::CONNECT . $big, 200)), new LiveOptions(maxMessageBytes: 1024));
        $this->open($connection);

        $this->expectException(TransportException::class);
        $connection->read($this->timeout());
    }

    #[Test]
    public function readOnAConnectionThatWasNeverOpenedOrIsClosedFails(): void
    {
        $connection = $this->connection(new FakeHttpClient($this->ok(self::CONNECT . self::EVENT_A)));
        try {
            $connection->read($this->timeout());
            self::fail('expected a TransportException');
        } catch (TransportException) {
            // never opened
        }

        $this->open($connection);
        $connection->close();

        $this->expectException(TransportException::class);
        $connection->read($this->timeout());
    }

    #[Test]
    public function subscribeIsRefusedAndPongDoesNothing(): void
    {
        $http = new FakeHttpClient($this->ok(self::CONNECT));
        $connection = $this->connection($http);
        $this->open($connection);

        $connection->pong();
        self::assertCount(1, $http->requests());
        self::assertFalse($connection->subscribesAfterConnect());

        $this->expectException(\LogicException::class);
        $connection->subscribe('other');
    }

    #[Test]
    public function closeIsIdempotentAndSafeBeforeOpen(): void
    {
        $stream = ChunkedStream::of(self::CONNECT, 4096);
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));

        $connection->close();
        $this->open($connection);
        $connection->close();
        $connection->close();

        self::assertTrue($stream->wasClosed());
    }

    #[Test]
    public function closeIgnoresAFailureOfAnAlreadyBrokenStream(): void
    {
        $stream = new HostileStream(ChunkedStream::of(self::CONNECT, 4096), 'close', new \RuntimeException('broken'));
        $connection = $this->connection(new FakeHttpClient($this->ok($stream)));
        $this->open($connection);

        $connection->close();

        $this->expectException(TransportException::class);
        $connection->read($this->timeout());
    }

    #[Test]
    public function openingAgainClosesThePreviousStream(): void
    {
        $first = ChunkedStream::of(self::CONNECT, 4096);
        $connection = $this->connection(new FakeHttpClient($this->ok($first), $this->ok(self::CONNECT)));

        $this->open($connection);
        $this->open($connection);

        self::assertTrue($first->wasClosed());
    }

    #[Test]
    public function aClientExceptionBecomesAnUnchainedTransportExceptionWithoutSecrets(): void
    {
        $connection = $this->connection(new FakeHttpClient(FakeHttpClient::networkErrorHoldingTheRequest()));

        $e = TraceArguments::caught(fn() => $this->open($connection));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious());
        $this->assertNoSecret($e);
        self::assertStringNotContainsString('api.example.test', $e->getMessage());
    }

    #[Test]
    public function aClientThatThrowsSomethingElseIsAlsoUnchained(): void
    {
        $connection = $this->connection(new FakeHttpClient(new \RuntimeException('failed for ' . $this->jwt)));

        $e = TraceArguments::caught(fn() => $this->open($connection));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious());
        $this->assertNoSecret($e);
    }

    #[Test]
    public function aChannelThatCannotBeEncodedFailsWithoutTheToken(): void
    {
        $http = new FakeHttpClient($this->ok(self::CONNECT));
        $connection = $this->connection($http);

        $e = TraceArguments::caught(fn() => $this->open($connection, ["chan\xff"]));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious());
        $this->assertNoSecret($e);
        self::assertSame([], $http->requests());
    }

    #[Test]
    public function aBaseUrlTheRequestFactoryRejectsFailsWithoutTheToken(): void
    {
        $http = new FakeHttpClient($this->ok(self::CONNECT));
        $connection = new SseConnection($http, GuzzleClientFactory::messageFactory(), 'https://api.example.test:abc', new LiveOptions());

        $e = TraceArguments::caught(fn() => $this->open($connection));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious());
        $this->assertNoSecret($e);
        self::assertStringNotContainsString('api.example.test', $e->getMessage());
    }

    /**
     * @return iterable<string, array{\Closure(self): SseConnection, string}>
     */
    public static function failuresAfterTheRequest(): iterable
    {
        yield '401' => [static fn(self $t): SseConnection => $t->connection(new FakeHttpClient(new Response(401, [], 'denied'))), 'open'];
        yield 'gzip' => [static fn(self $t): SseConnection => $t->connection(new FakeHttpClient(new Response(200, ['Content-Encoding' => 'gzip'], 'x'))), 'open'];
        yield 'not a connect reply' => [static fn(self $t): SseConnection => $t->connection(new FakeHttpClient($t->ok(self::EVENT_A))), 'open'];
        yield 'stream ended' => [static fn(self $t): SseConnection => $t->connection(new FakeHttpClient($t->ok(self::CONNECT))), 'read'];
    }

    /**
     * @param \Closure(self): SseConnection $make
     */
    #[Test]
    #[DataProvider('failuresAfterTheRequest')]
    public function theTokenIsInNoExceptionMessageChainOrTraceArgument(\Closure $make, string $phase): void
    {
        $connection = $make($this);

        $e = TraceArguments::caught(function () use ($connection, $phase): void {
            $this->open($connection);
            if ($phase === 'read') {
                $connection->read($this->timeout());
            }
        });

        $this->assertNoSecret($e);
    }

    /**
     * The decoded `cf_connect` value, the only query parameter.
     */
    private function connectParameter(RequestInterface $request): string
    {
        parse_str($request->getUri()->getQuery(), $query);
        self::assertSame(['cf_connect'], array_keys($query));
        $value = $query['cf_connect'] ?? null;
        self::assertIsString($value);

        return $value;
    }

    private function assertNoSecret(\Throwable $e): void
    {
        $fragments = [$this->jwt, explode('.', $this->jwt)[1] ?? '', 'cf_connect'];
        for ($current = $e; $current instanceof \Throwable; $current = $current->getPrevious()) {
            $text = $current->getMessage() . $current->getTraceAsString();
            foreach ($fragments as $fragment) {
                self::assertStringNotContainsString($fragment, $text);
            }
        }
        foreach ([TraceArguments::exportedSdkFrames($e), TraceArguments::printedSdkFrames($e)] as $frames) {
            foreach ($fragments as $fragment) {
                self::assertStringNotContainsString($fragment, $frames);
            }
        }
    }
}
