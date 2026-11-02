<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;
use Psr\Log\AbstractLogger;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\BadRequestException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GeneralHttpException;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\FixedRandomSource;
use WhiteMarket\Sdk\Tests\Support\FrozenClock;
use WhiteMarket\Sdk\Tests\Support\MemoryLogger;
use WhiteMarket\Sdk\Tests\Support\RecordingSleeper;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Transport\HttpClients;
use WhiteMarket\Sdk\Transport\HttpTransport;
use WhiteMarket\Sdk\Transport\RetryLoop;
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\Transport\TransportOptions;
use WhiteMarket\Sdk\Version;

#[CoversClass(HttpTransport::class)]
#[CoversClass(RetryLoop::class)]
final class HttpTransportTest extends TestCase
{
    private const TOKEN = 'synthetic-jwt-7f3a';

    private const BODY = '{"query":"query Q { test_ping }","operationName":"Q","variables":{}}';

    private RecordingSleeper $sleeper;

    private MemoryLogger $logger;

    protected function setUp(): void
    {
        $this->sleeper = new RecordingSleeper();
        $this->logger = new MemoryLogger();
    }

    #[Test]
    public function postsTheBodyWithTheSdkHeadersAndTheCredentials(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, '{"data":{}}'));

        $body = $this->post($http, OperationType::Query, ['Authorization' => new Secret('Bearer ' . self::TOKEN)], options: new TransportOptions(baseUrl: 'https://api.example.test/', userAgentSuffix: 'my-bot/1.0'));

        self::assertSame('{"data":{}}', $body);
        $request = $http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.test/graphql/partner', (string) $request->getUri());
        self::assertSame([
            'Host' => ['api.example.test'],
            'Content-Type' => ['application/json'],
            'Accept' => ['application/json'],
            'Accept-Encoding' => ['gzip'],
            'User-Agent' => [Version::userAgent() . ' my-bot/1.0'],
            'Authorization' => ['Bearer ' . self::TOKEN],
        ], $request->getHeaders());
        self::assertSame(self::BODY, $http->lastBody());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidHeaders(): iterable
    {
        yield 'line break in the value' => ['Authorization', "Bearer %s\r\nX-Injected: 1"];
        yield 'leading space' => ['Authorization', ' Bearer %s'];
        yield 'trailing space' => ['Authorization', 'Bearer %s '];
        yield 'non-ASCII value' => ['Authorization', "Bearer %s\u{e9}"];
        yield 'invalid name' => ["X-Bad\nName", '%s'];
        // A value read from a file or an env variable often ends with a line feed.
        yield 'trailing line feed in the value' => ['Authorization', "Bearer %s\n"];
        yield 'trailing line feed in the name' => ["Authorization\n", 'Bearer %s'];
    }

    #[Test]
    #[DataProvider('invalidHeaders')]
    public function rejectsAnInvalidCredentialHeaderWithoutRevealingIt(string $name, string $template): void
    {
        $http = new FakeHttpClient();

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query, [$name => new Secret(\sprintf($template, self::TOKEN))]));

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
        // Rejected by the SDK itself: the checks of another PSR-7 implementation may put the value into the message.
        self::assertSame((new \ReflectionClass(HttpTransport::class))->getFileName(), $e->getFile());
        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        self::assertStringNotContainsString(self::TOKEN, TraceArguments::exportedSdkFrames($e));
        self::assertSame([], $http->requests());
    }

    /**
     * @return iterable<string, array{array<array-key, string>}>
     */
    public static function headerSetsWithALaterInvalidHeader(): iterable
    {
        yield 'invalid name' => [['Authorization' => 'Bearer %s', "X-Bad\nName" => 'x']];
        yield 'invalid value' => [['Authorization' => 'Bearer %s', 'X-Second' => "x\r\nX-Injected: 1"]];
        // PHP turns the key "123" into an int, which the string parameter for the name rejects with a TypeError.
        yield 'integer-like name' => [['Authorization' => 'Bearer %s', '123' => 'x']];
    }

    /**
     * @param array<array-key, string> $templates
     */
    #[Test]
    #[DataProvider('headerSetsWithALaterInvalidHeader')]
    public function keepsEarlierCredentialHeadersOutOfTheTraceWhenALaterOneIsRejected(array $templates): void
    {
        $headers = array_map(static fn(string $template): Secret => new Secret(\sprintf($template, self::TOKEN)), $templates);
        $http = new FakeHttpClient();

        /** @var array<string, Secret> $headers PHP keeps "123" as an int key; the dataset is about that case. */
        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query, $headers));

        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        self::assertStringNotContainsString(self::TOKEN, TraceArguments::exportedSdkFrames($e));
        self::assertSame([], $http->requests());
    }

    /**
     * @return iterable<string, array{\Throwable}>
     */
    public static function failuresOutsidePsr18(): iterable
    {
        yield 'exception' => [new \RuntimeException('synthetic middleware failure')];
        yield 'error' => [new \Error('synthetic middleware bug')];
    }

    #[Test]
    #[DataProvider('failuresOutsidePsr18')]
    public function reportsAnyOtherClientFailureWithoutTheRequest(\Throwable $failure): void
    {
        // A non-default client or middleware throwing outside PSR-18: its own frames hold the request.
        $http = new FakeHttpClient($failure);

        $e = TraceArguments::caught(fn(): string => $this->transport($http)->post('Q', OperationType::Query, self::BODY, ['Authorization' => new Secret('Bearer ' . self::TOKEN)]));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious(), 'the failure was thrown with the request as a trace argument');
        self::assertStringContainsString($failure::class, $e->getMessage());
        self::assertStringNotContainsString(self::TOKEN, TraceArguments::exportedSdkFrames($e));
        self::assertCount(1, $http->requests());
        self::assertSame([], $this->sleeper->delays());
    }

    /**
     * @return iterable<string, array{\Closure(string): \Throwable}>
     */
    public static function headerRejections(): iterable
    {
        yield 'InvalidArgumentException, as PSR-7 specifies' => [static fn(string $value): \Throwable => new \InvalidArgumentException(\sprintf('"%s" is not valid header value.', $value))];
        // Outside the PSR-7 contract: a non-compliant implementation, or a warning turned into an exception by a handler.
        yield 'another exception' => [static fn(string $value): \Throwable => new \RuntimeException(\sprintf('bad %s', $value))];
        yield 'an error' => [static fn(string $value): \Throwable => new \TypeError(\sprintf('bad %s', $value))];
    }

    /**
     * @param \Closure(string): \Throwable $rejection
     */
    #[Test]
    #[DataProvider('headerRejections')]
    public function hidesTheValueWhenThePsr7ImplementationRejectsAHeader(\Closure $rejection): void
    {
        // A PSR-7 implementation stricter than the SDK, which names the rejected value like Guzzle does.
        $strict = new class ($rejection) implements RequestFactoryInterface {
            /**
             * @param \Closure(string): \Throwable $rejection
             */
            public function __construct(private readonly \Closure $rejection) {}

            /**
             * @param UriInterface|string $uri
             */
            public function createRequest(string $method, $uri): RequestInterface
            {
                return new class ($this->rejection, $method, $uri) extends Request {
                    /**
                     * @param \Closure(string): \Throwable $rejection
                     * @param UriInterface|string $uri
                     */
                    public function __construct(private readonly \Closure $rejection, string $method, $uri)
                    {
                        parent::__construct($method, $uri);
                    }

                    /**
                     * @param string $header
                     * @param string|string[] $value
                     */
                    public function withHeader($header, $value): MessageInterface
                    {
                        if ($header === 'Authorization') {
                            throw ($this->rejection)(\is_array($value) ? implode(', ', $value) : $value);
                        }

                        return parent::withHeader($header, $value);
                    }
                };
            }
        };
        $http = new FakeHttpClient();
        $transport = $this->transport($http, requestFactory: $strict);

        $e = TraceArguments::caught(static fn(): string => $transport->post('Q', OperationType::Query, self::BODY, ['Authorization' => new Secret('Bearer ' . self::TOKEN)]));

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
        self::assertStringNotContainsString(self::TOKEN, $e->getMessage());
        self::assertNull($e->getPrevious(), 'the rejected exception names the value');
        self::assertStringNotContainsString(self::TOKEN, TraceArguments::exportedSdkFrames($e));
        self::assertSame([], $http->requests());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryableStatuses(): iterable
    {
        yield '429' => [429];
        yield '502' => [502];
        yield '503' => [503];
        yield '504' => [504];
    }

    #[Test]
    #[DataProvider('retryableStatuses')]
    public function repeatsAQueryOnARetryableStatus(int $status): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status($status), FakeHttpClient::status(200, 'ok'));

        self::assertSame('ok', $this->post($http, OperationType::Query));
        self::assertCount(2, $http->requests());
        self::assertSame([0.25], $this->sleeper->delays(), 'full jitter: fraction 0.5 of the 0.5 s base');
    }

    #[Test]
    public function repeatsAQueryAfterANetworkErrorWithAFreshRequest(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::networkError(), FakeHttpClient::status(200, 'ok'));

        self::assertSame('ok', $this->post($http, OperationType::Query));
        $requests = $http->requests();
        self::assertCount(2, $requests);
        self::assertNotSame($requests[0], $requests[1]);
        self::assertSame(self::BODY, $http->lastBody());
    }

    #[Test]
    public function stopsAfterTheLastAttemptWithTheStatusException(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(502), FakeHttpClient::status(502), FakeHttpClient::status(502, 'bad gateway'));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query));

        self::assertInstanceOf(GeneralServerException::class, $e);
        self::assertSame('bad gateway', $e->bodyExcerpt);
        self::assertCount(3, $http->requests());
        self::assertSame([0.25, 0.5], $this->sleeper->delays());
    }

    #[Test]
    public function stopsAfterTheLastNetworkErrorWithATransportException(): void
    {
        $failure = FakeHttpClient::networkErrorHoldingTheRequest();
        $http = new FakeHttpClient($failure, $failure, $failure);

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query, ['Authorization' => new Secret('Bearer ' . self::TOKEN)]));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious(), 'the client exception holds the request with its Authorization header');
        self::assertStringNotContainsString(self::TOKEN, TraceArguments::exportedSdkFrames($e));
        self::assertCount(3, $http->requests());
    }

    /**
     * @return iterable<string, array{ResponseInterface|ClientExceptionInterface}>
     */
    public static function mutationFailures(): iterable
    {
        yield '503' => [FakeHttpClient::status(503)];
        yield '429' => [FakeHttpClient::status(429)];
        yield '502' => [FakeHttpClient::status(502)];
        yield 'network error' => [FakeHttpClient::networkError()];
    }

    #[Test]
    #[DataProvider('mutationFailures')]
    public function neverRepeatsAMutation(ResponseInterface|ClientExceptionInterface $failure): void
    {
        $http = new FakeHttpClient($failure, FakeHttpClient::status(200, 'must not be requested'));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Mutation));

        self::assertTrue($e instanceof HttpException || $e instanceof TransportException);
        self::assertCount(1, $http->requests());
        self::assertSame([], $this->sleeper->delays());
    }

    #[Test]
    public function sendsMutationsThroughTheMutationClient(): void
    {
        $queries = new FakeHttpClient(FakeHttpClient::status(200, '{"data":{}}'));
        $mutations = new FakeHttpClient(FakeHttpClient::status(200, '{"data":{}}'));
        $transport = $this->transport($queries, $mutations);

        $transport->post('Q', OperationType::Query, self::BODY, []);
        $transport->post('M', OperationType::Mutation, '{"query":"mutation M { test_ping }"}', []);

        self::assertSame([self::BODY], array_map(static fn(RequestInterface $request): string => (string) $request->getBody(), $queries->requests()));
        self::assertSame(['{"query":"mutation M { test_ping }"}'], array_map(static fn(RequestInterface $request): string => (string) $request->getBody(), $mutations->requests()));
    }

    /**
     * @return iterable<string, array{int, string, class-string<HttpException>, int}>
     */
    public static function retryAfterResponses(): iterable
    {
        yield '503, seconds' => [503, '7', ServiceUnavailableException::class, 7];
        yield '503, zero seconds' => [503, '0', ServiceUnavailableException::class, 0];
        yield '429, HTTP-date' => [429, 'Thu, 01 Jan 2026 00:00:12 GMT', RateLimitException::class, 12];
        yield '429, one hour' => [429, '3600', RateLimitException::class, 3600];
        yield '503, obsolete RFC 850 date' => [503, 'Thursday, 01-Jan-26 00:00:12 GMT', ServiceUnavailableException::class, 12];
        yield '429, obsolete asctime date' => [429, 'Thu Jan  1 00:00:12 2026', RateLimitException::class, 12];
    }

    /**
     * @param class-string<HttpException> $class
     */
    #[Test]
    #[DataProvider('retryAfterResponses')]
    public function leavesTheRetryToTheCallerWhenTheServerSaysWhen(int $status, string $header, string $class, int $retryAfter): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status($status, '', ['Retry-After' => $header]), FakeHttpClient::status(200, 'ok'));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query));

        self::assertInstanceOf($class, $e);
        self::assertSame($retryAfter, $e->retryAfter);
        self::assertCount(1, $http->requests());
        self::assertSame([], $this->sleeper->delays());
    }

    #[Test]
    public function backsOffWhenA502CarriesRetryAfter(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(502, '', ['Retry-After' => '7']), FakeHttpClient::status(200, 'ok'));

        self::assertSame('ok', $this->post($http, OperationType::Query));
        self::assertSame([0.25], $this->sleeper->delays(), 'the computed backoff, not the header');
    }

    /**
     * @return iterable<string, array{int, class-string<HttpException>}>
     */
    public static function statuses(): iterable
    {
        yield '400' => [400, BadRequestException::class];
        yield '401' => [401, AuthenticationException::class];
        yield '404' => [404, GeneralHttpException::class];
        yield '501 is not retried' => [501, GeneralServerException::class];
        yield '507 is not retried (a query retries the table of SDK_SPEC 1.4 only)' => [507, GeneralServerException::class];
        yield '302 is not followed' => [302, GeneralHttpException::class];
        yield '199' => [199, GeneralHttpException::class];
        yield '300' => [300, GeneralHttpException::class];
        yield '503 with Retry-After' => [503, ServiceUnavailableException::class];
    }

    /**
     * @param class-string<HttpException> $expected
     */
    #[Test]
    #[DataProvider('statuses')]
    public function mapsANonSuccessStatusOnTheFirstAttempt(int $status, string $expected): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status($status, '{"error":{"code":1,"token":"' . self::TOKEN . '"}}', ['Retry-After' => '3600', 'Location' => 'https://elsewhere.example.test/']));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query));

        self::assertInstanceOf($expected, $e);
        self::assertSame($status, $e->status);
        self::assertSame('{"error":{"code":1,"token":"***"}}', $e->bodyExcerpt);
        self::assertCount(1, $http->requests());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function successStatuses(): iterable
    {
        yield '200' => [200];
        yield '204' => [204];
        yield '299' => [299];
    }

    #[Test]
    #[DataProvider('successStatuses')]
    public function returnsTheBodyOfAnySuccessStatus(int $status): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status($status, 'body'));

        self::assertSame('body', $this->post($http, OperationType::Query));
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function sizes(): iterable
    {
        yield 'one byte under the limit' => [99, true];
        yield 'exactly the limit' => [100, true];
        yield 'one byte over the limit' => [101, false];
    }

    #[Test]
    #[DataProvider('sizes')]
    public function limitsTheResponseBody(int $bytes, bool $accepted): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, str_repeat('a', $bytes)));

        $act = fn(): string => $this->post($http, OperationType::Query, options: new TransportOptions(maxResponseBytes: 100));

        if ($accepted) {
            self::assertSame($bytes, \strlen($act()));
        } else {
            self::assertInstanceOf(TransportException::class, TraceArguments::caught($act));
        }
    }

    #[Test]
    public function decompressesAGzipBody(): void
    {
        $plain = str_repeat('{"data":{"test_ping":"pong"}}', 1000);
        $http = new FakeHttpClient(FakeHttpClient::status(200, (string) gzencode($plain), ['Content-Encoding' => 'gzip']));

        self::assertSame($plain, $this->post($http, OperationType::Query));
    }

    #[Test]
    public function limitsTheDecompressedSizeNotTheCompressedOne(): void
    {
        $bomb = (string) gzencode(str_repeat("\0", 10 * 1024 * 1024));
        $http = new FakeHttpClient(FakeHttpClient::status(200, $bomb, ['Content-Encoding' => 'gzip']));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query, options: new TransportOptions(maxResponseBytes: 1024 * 1024)));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertLessThan(1024 * 1024, \strlen($bomb));
    }

    #[Test]
    public function readsAGzipBodyOfSeveralMembersUpToTheEndOfTheFirst(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, gzencode('{"a":1}') . gzencode('{"b":2}'), ['Content-Encoding' => 'gzip']));

        self::assertSame('{"a":1}', $this->post($http, OperationType::Query));
    }

    /**
     * @return iterable<string, array{string, array<string, string>}>
     */
    public static function unreadableBodies(): iterable
    {
        yield 'corrupt gzip' => ['not gzip at all', ['Content-Encoding' => 'gzip']];
        yield 'truncated gzip' => [substr((string) gzencode(str_repeat('x', 5000)), 0, 20), ['Content-Encoding' => 'gzip']];
        yield 'unsupported encoding' => ['data', ['Content-Encoding' => 'br']];
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('unreadableBodies')]
    public function failsOnABodyItCannotDecode(string $body, array $headers): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, $body, $headers));

        self::assertInstanceOf(TransportException::class, TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query)));
    }

    #[Test]
    public function cutsAnOversizedErrorBodyInsteadOfFailing(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(400, str_repeat('a', 200)));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query, options: new TransportOptions(maxResponseBytes: 100)));

        self::assertInstanceOf(BadRequestException::class, $e);
        self::assertSame(str_repeat('a', 100), $e->bodyExcerpt);
    }

    #[Test]
    public function keepsWhatItDecodedOfACorruptGzipErrorBody(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(400, 'not gzip', ['Content-Encoding' => 'gzip']));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query));

        self::assertInstanceOf(BadRequestException::class, $e);
        self::assertSame('', $e->bodyExcerpt);
    }

    #[Test]
    public function reportsAFailedBodyReadAsATransportError(): void
    {
        $stream = new class (Utils::tryFopen('php://memory', 'r+')) extends Stream {
            public function read($length): string
            {
                throw new \RuntimeException('synthetic read failure');
            }
        };
        $http = new FakeHttpClient(new Response(200, [], $stream));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious());
    }

    /**
     * @return iterable<string, array{\Closure(string): \Throwable}> the exception a client throws, from the credential it holds
     */
    public static function sdkExceptionsFromTheClient(): iterable
    {
        yield 'AuthenticationException' => [static fn(string $credential): \Throwable => new AuthenticationException(401, $credential)];
        yield 'HttpException of a server error' => [static fn(string $credential): \Throwable => new GeneralServerException(500, $credential)];
        yield 'TransportException chaining a credential' => [static fn(string $credential): \Throwable => new TransportException('client', 0, new \RuntimeException($credential))];
        yield 'DecodeException chaining a credential' => [static fn(string $credential): \Throwable => new DecodeException('data', 'bad', new \RuntimeException($credential))];
    }

    /**
     * @param \Closure(string): \Throwable $failure
     */
    #[Test]
    #[DataProvider('sdkExceptionsFromTheClient')]
    public function anSdkExceptionThrownByTheClientIsAClientFailureNotAnSdkOutcome(\Closure $failure): void
    {
        // CLAUDE.md rule 2: the client's exception must not look like a server answer (a mutation would be classified as
        // rejected or replayed) and must not carry what the client chained to it.
        $http = new FakeHttpClient($failure('Bearer ' . self::TOKEN), FakeHttpClient::status(200, 'must not be requested'));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Mutation, ['Authorization' => new Secret('Bearer ' . self::TOKEN)]));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious());
        self::assertStringNotContainsString(self::TOKEN, $e->getMessage() . TraceArguments::exportedSdkFrames($e));
        self::assertCount(1, $http->requests());
    }

    #[Test]
    public function doesNotRepeatARequestTheClientRejected(): void
    {
        $rejected = new RequestException('synthetic invalid request', (new HttpFactory())->createRequest('POST', 'https://example.invalid/'));
        $http = new FakeHttpClient($rejected, FakeHttpClient::status(200, 'must not be requested'));

        $e = TraceArguments::caught(fn(): string => $this->post($http, OperationType::Query));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertNull($e->getPrevious());
        self::assertCount(1, $http->requests());
    }

    /**
     * The client keeps every request it sent, as a recording or caching PSR-18 client does; a closure handed to the
     * retry loop reaches it through what it captured (the client, or `$this` and the clients it holds).
     */
    #[Test]
    #[DataProvider('failedSends')]
    public function aPrintedTraceShowsNoRequestTheClientKept(ResponseInterface|\Throwable $outcome): void
    {
        $http = new FakeHttpClient($outcome);

        $e = TraceArguments::caught(fn(): string => $this->transport($http)->post('M', OperationType::Mutation, self::BODY, ['Authorization' => new Secret('Bearer ' . self::TOKEN)]));

        self::assertCount(1, $http->requests());
        self::assertStringNotContainsString(self::TOKEN, TraceArguments::printedSdkFrames($e));
    }

    /**
     * @return iterable<string, array{ResponseInterface|\Throwable}>
     */
    public static function failedSends(): iterable
    {
        yield 'HTTP 500' => [FakeHttpClient::status(500)];
        yield 'network error' => [FakeHttpClient::networkError()];
    }

    #[Test]
    public function noTraceArgumentHoldsTheCredentialInAClosureOrARequest(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::networkError());
        $headers = ['Authorization' => new Secret('Bearer ' . self::TOKEN)];

        $e = TraceArguments::caught(fn(): string => $this->transport($http)->post('M', OperationType::Mutation, self::BODY, $headers));

        $frames = 0;
        foreach ($e->getTrace() as $frame) {
            $class = $frame['class'] ?? '';
            if (!str_starts_with($class, 'WhiteMarket\\Sdk\\') || str_contains($class, '\\Tests\\')) {
                continue; // the test side holds the credential by construction
            }
            foreach ($frame['args'] ?? [] as $argument) {
                ++$frames;
                self::assertNotInstanceOf(RequestInterface::class, $argument, 'a request holds the credential headers');
                if ($argument instanceof \Closure) {
                    foreach ((new \ReflectionFunction($argument))->getStaticVariables() as $captured) {
                        self::assertNotInstanceOf(Secret::class, $captured);
                        self::assertFalse(\is_array($captured) && array_filter($captured, static fn(mixed $v): bool => $v instanceof Secret) !== [], 'the closure captured the credential headers');
                    }
                }
            }
        }
        self::assertGreaterThan(0, $frames, 'the trace has arguments, so the check above looked at something');
        $run = array_values(array_filter($e->getTrace(), static fn(array $frame): bool => ($frame['class'] ?? '') === RetryLoop::class && $frame['function'] === 'run'));
        self::assertCount(1, $run);
        self::assertInstanceOf(\SensitiveParameterValue::class, $run[0]['args'][3] ?? null, 'the $build closure captures the headers');
    }

    #[Test]
    public function logsEveryAttemptWithoutHeadersOrBodies(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::networkError(), FakeHttpClient::status(503), FakeHttpClient::status(200, '{"data":{"token":"' . self::TOKEN . '"}}'));

        $this->post($http, OperationType::Query, ['Authorization' => new Secret('Bearer ' . self::TOKEN)]);

        self::assertSame(
            [
                ['debug', 'WhiteMarket API request', ['operation' => 'Q', 'type' => 'query', 'attempt' => 1, 'status' => null]],
                ['info', 'Retrying WhiteMarket API request', ['operation' => 'Q', 'attempt' => 1, 'delaySeconds' => 0.25]],
                ['debug', 'WhiteMarket API request', ['operation' => 'Q', 'type' => 'query', 'attempt' => 2, 'status' => 503]],
                ['info', 'Retrying WhiteMarket API request', ['operation' => 'Q', 'attempt' => 2, 'delaySeconds' => 0.5]],
                ['debug', 'WhiteMarket API request', ['operation' => 'Q', 'type' => 'query', 'attempt' => 3, 'status' => 200]],
            ],
            array_map(
                static function (array $record): array {
                    unset($record['context']['durationMs']);

                    return [$record['level'], $record['message'], $record['context']];
                },
                $this->logger->records(),
            ),
        );
        self::assertStringNotContainsString(self::TOKEN, $this->logger->dump());
    }

    /**
     * @return iterable<string, array{OperationType}>
     */
    public static function operationTypes(): iterable
    {
        yield 'query' => [OperationType::Query];
        yield 'mutation' => [OperationType::Mutation];
    }

    #[Test]
    #[DataProvider('operationTypes')]
    public function aLoggerThatThrowsNeverChangesTheOutcomeOfARequest(OperationType $type): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, '{"data":{"ok":true}}'));
        $throwing = new class extends AbstractLogger {
            /** @param array<mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \UnexpectedValueException('The stream or file could not be opened');
            }
        };
        $transport = new HttpTransport(new HttpClients($http, $http), new HttpFactory(), new HttpFactory(), logger: $throwing, sleeper: $this->sleeper);

        $body = $transport->post('Q', $type, self::BODY, []);

        self::assertSame('{"data":{"ok":true}}', $body);
        self::assertCount(1, $http->requests(), 'the request is sent once, whatever the logger does');
    }

    #[Test]
    public function aLoggerThatThrowsDoesNotStopTheRetryOfAQuery(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::status(200, '{"data":{}}'));
        $throwing = new class extends AbstractLogger {
            /** @param array<mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \UnexpectedValueException('logger down');
            }
        };
        $transport = new HttpTransport(new HttpClients($http, $http), new HttpFactory(), new HttpFactory(), logger: $throwing, sleeper: $this->sleeper, random: new FixedRandomSource(0.5));

        self::assertSame('{"data":{}}', $transport->post('Q', OperationType::Query, self::BODY, []));
        self::assertCount(2, $http->requests());
    }

    /**
     * @param array<string, Secret> $headers
     */
    private function post(FakeHttpClient $http, OperationType $type, array $headers = [], TransportOptions $options = new TransportOptions()): string
    {
        return $this->transport($http, options: $options)->post('Q', $type, self::BODY, $headers);
    }

    private function transport(
        ClientInterface $http,
        ?ClientInterface $mutationClient = null,
        RequestFactoryInterface $requestFactory = new HttpFactory(),
        TransportOptions $options = new TransportOptions(),
    ): HttpTransport {
        return new HttpTransport(
            new HttpClients($http, $mutationClient ?? $http),
            $requestFactory,
            new HttpFactory(),
            $options,
            new RetryPolicy(),
            $this->logger,
            new FrozenClock('2026-01-01T00:00:00+00:00'),
            $this->sleeper,
            new FixedRandomSource(0.5),
        );
    }
}
