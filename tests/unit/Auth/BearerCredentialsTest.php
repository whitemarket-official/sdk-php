<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use WhiteMarket\Sdk\Auth\BearerCredentials;
use WhiteMarket\Sdk\Auth\InMemoryTokenStore;
use WhiteMarket\Sdk\Auth\PartnerTokenProvider;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\StaticAccessToken;
use WhiteMarket\Sdk\Auth\TokenProvider;
use WhiteMarket\Sdk\Auth\TokenStore;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\GraphQL\GraphQLExecutor;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\FrozenClock;
use WhiteMarket\Sdk\Tests\Support\SyntheticJwt;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

/**
 * 401 -> refresh once -> replay once (SDK_SPEC 1.4), through the executor and the transport with a fake HTTP client.
 */
#[CoversClass(BearerCredentials::class)]
#[CoversClass(GraphQLExecutor::class)]
final class BearerCredentialsTest extends TestCase
{
    private const NOW = '2026-01-01T00:00:00+00:00';

    private const PARTNER_TOKEN = 'synthetic-partner-token-c41d';

    private const EXPIRED = '{"code":401,"message":"Expired JWT Token"}';

    private const DATA = ['data' => ['x' => 1]];

    private string $first;

    private string $second;

    protected function setUp(): void
    {
        $now = new \DateTimeImmutable(self::NOW);
        $this->first = SyntheticJwt::issuedAt($now, 86_400, 'first');
        $this->second = SyntheticJwt::issuedAt($now, 86_400, 'second');
    }

    #[Test]
    public function sendsTheProviderTokenAsBearer(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(self::DATA));

        $this->executor($http, new StaticAccessToken(new Secret($this->first)))->execute('query Q { x }', 'Q', OperationType::Query, []);

        self::assertSame(['Bearer ' . $this->first], $http->lastRequest()->getHeader('Authorization'));
    }

    /**
     * @return iterable<string, array{OperationType}>
     */
    public static function operationTypes(): iterable
    {
        yield 'query' => [OperationType::Query];
        yield 'mutation (rejected before it ran)' => [OperationType::Mutation];
    }

    #[Test]
    #[DataProvider('operationTypes')]
    public function refreshesAndReplaysOnceAfterA401(OperationType $type): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::authToken($this->first),
            FakeHttpClient::status(401, self::EXPIRED),
            FakeHttpClient::authToken($this->second),
            FakeHttpClient::json(self::DATA),
        );

        $data = $this->executor($http, $this->partnerProvider($http, new InMemoryTokenStore()))->execute('mutation M { x }', 'M', $type, []);

        self::assertSame(['x' => 1], $data);
        self::assertSame(
            [['AuthToken', null], ['M', 'Bearer ' . $this->first], ['AuthToken', null], ['M', 'Bearer ' . $this->second]],
            $this->sent($http),
        );
        $requests = $http->requests();
        self::assertSame((string) ($requests[1] ?? null)?->getBody(), (string) ($requests[3] ?? null)?->getBody(), 'the same body is replayed');
    }

    #[Test]
    #[DataProvider('operationTypes')]
    public function anAuthenticationExceptionThrownByTheClientIsNotAnExpiredToken(OperationType $type): void
    {
        // The client's exception is not a server answer: no refresh, no replay (a mutation would be sent twice).
        $http = new FakeHttpClient(
            FakeHttpClient::authToken($this->first),
            new AuthenticationException(401, self::EXPIRED),
            FakeHttpClient::authToken($this->second),
            FakeHttpClient::json(self::DATA),
        );

        $e = TraceArguments::caught(fn(): array => $this->executor($http, $this->partnerProvider($http, new InMemoryTokenStore()))->execute('mutation M { x }', 'M', $type, []));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame([['AuthToken', null], ['M', 'Bearer ' . $this->first]], $this->sent($http));
    }

    #[Test]
    public function aSecond401Propagates(): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::authToken($this->first),
            FakeHttpClient::status(401, self::EXPIRED),
            FakeHttpClient::authToken($this->second),
            FakeHttpClient::status(401, self::EXPIRED),
            FakeHttpClient::json(self::DATA),
        );

        $e = TraceArguments::caught(fn(): array => $this->executor($http, $this->partnerProvider($http, new InMemoryTokenStore()))->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(AuthenticationException::class, $e);
        self::assertCount(4, $http->requests());
        self::assertStringNotContainsString($this->second, $e->getMessage() . TraceArguments::exportedSdkFrames($e));
    }

    #[Test]
    public function aStaticTokenIsNotReplayed(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(401, self::EXPIRED), FakeHttpClient::json(self::DATA));

        $e = TraceArguments::caught(fn(): array => $this->executor($http, new StaticAccessToken(new Secret($this->first)))->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(AuthenticationException::class, $e);
        self::assertCount(1, $http->requests());
    }

    #[Test]
    public function replaysWithATokenAnotherWorkerStoredWithoutCallingAuthToken(): void
    {
        $store = new InMemoryTokenStore();
        $store->set(new Secret($this->first), new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $http = new FakeHttpClient(FakeHttpClient::status(401, self::EXPIRED), FakeHttpClient::json(self::DATA));
        $provider = $this->partnerProvider($http, $store);
        $executor = $this->executor($http, new class ($provider, $store, new Secret($this->second)) implements TokenProvider {
            private bool $rotated = false;

            public function __construct(private readonly TokenProvider $inner, private readonly TokenStore $store, private readonly Secret $next) {}

            public function accessToken(): Secret
            {
                $token = $this->inner->accessToken();
                if (!$this->rotated) {
                    // Another worker stores a new token while the first request is in flight.
                    $this->rotated = true;
                    $this->store->set($this->next, new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
                }

                return $token;
            }

            public function refresh(): Secret
            {
                return $this->inner->refresh();
            }
        });

        $executor->execute('query Q { x }', 'Q', OperationType::Query, []);

        self::assertSame([['Q', 'Bearer ' . $this->first], ['Q', 'Bearer ' . $this->second]], $this->sent($http));
    }

    #[Test]
    public function aRejectedPartnerTokenDuringTheRefreshPropagates(): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::authToken($this->first),
            FakeHttpClient::status(401, self::EXPIRED),
            FakeHttpClient::status(401, '{"code":401,"message":"Invalid partner token"}'),
            FakeHttpClient::json(self::DATA),
        );

        $e = TraceArguments::caught(fn(): array => $this->executor($http, $this->partnerProvider($http, new InMemoryTokenStore()))->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(AuthenticationException::class, $e);
        self::assertSame([['AuthToken', null], ['Q', 'Bearer ' . $this->first], ['AuthToken', null]], $this->sent($http));
        self::assertStringNotContainsString(self::PARTNER_TOKEN, $e->getMessage() . TraceArguments::exportedSdkFrames($e));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidBearerTokens(): iterable
    {
        yield 'CR LF' => ["a\r\nb"];
        yield 'space' => ['abc def'];
    }

    #[Test]
    #[DataProvider('invalidBearerTokens')]
    public function refusesATokenProviderValueThatIsNotAValidBearerTokenAndSendsNothing(string $token): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(self::DATA));
        $provider = new class (new Secret($token)) implements TokenProvider {
            public function __construct(private readonly Secret $token) {}

            public function accessToken(): Secret
            {
                return $this->token;
            }

            public function refresh(): Secret
            {
                return $this->token;
            }
        };

        $e = TraceArguments::caught(fn(): array => $this->executor($http, $provider)->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(\UnexpectedValueException::class, $e);
        self::assertSame('The token provider returned a value that is not a valid bearer token.', $e->getMessage());
        self::assertSame([], $http->requests());
    }

    #[Test]
    public function refusesAnInvalidRefreshedTokenAfterA401(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(401, self::EXPIRED), FakeHttpClient::json(self::DATA));
        $provider = new class ($this->first) implements TokenProvider {
            public function __construct(private readonly string $first) {}

            public function accessToken(): Secret
            {
                return new Secret($this->first);
            }

            public function refresh(): Secret
            {
                return new Secret("a\r\nb");
            }
        };

        $e = TraceArguments::caught(fn(): array => $this->executor($http, $provider)->execute('query Q { x }', 'Q', OperationType::Query, []));

        self::assertInstanceOf(\UnexpectedValueException::class, $e);
        self::assertCount(1, $http->requests(), 'the invalid token is not sent');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function poisonedStoredTokens(): iterable
    {
        yield 'CR LF' => ["x\r\nX-Injected: 1"];
        yield 'space and comma' => ['abc def,ghi'];
    }

    #[Test]
    #[DataProvider('poisonedStoredTokens')]
    public function sendsTheFreshTokenWhenTheStoreHeldAnInvalidOne(string $poisoned): void
    {
        $store = new InMemoryTokenStore();
        $store->set(new Secret($poisoned), new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $http = new FakeHttpClient(FakeHttpClient::authToken($this->first), FakeHttpClient::json(self::DATA));

        $this->executor($http, $this->partnerProvider($http, $store))->execute('query Q { x }', 'Q', OperationType::Query, []);

        self::assertSame([['AuthToken', null], ['Q', 'Bearer ' . $this->first]], $this->sent($http));
    }

    /**
     * @return list<array{mixed, ?string}> operation name and Authorization header of every request
     */
    private function sent(FakeHttpClient $http): array
    {
        return array_map(
            static function (RequestInterface $request): array {
                $payload = json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);

                return [\is_array($payload) ? $payload['operationName'] ?? null : null, $request->hasHeader('Authorization') ? $request->getHeaderLine('Authorization') : null];
            },
            $http->requests(),
        );
    }

    private function partnerProvider(FakeHttpClient $http, TokenStore $store): PartnerTokenProvider
    {
        return new PartnerTokenProvider(TestTransport::over($http, new FrozenClock(self::NOW)), new Secret(self::PARTNER_TOKEN), $store, new FrozenClock(self::NOW));
    }

    private function executor(FakeHttpClient $http, TokenProvider $provider): GraphQLExecutor
    {
        return new GraphQLExecutor(TestTransport::over($http, new FrozenClock(self::NOW)), new BearerCredentials($provider));
    }
}
