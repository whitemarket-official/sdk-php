<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\InMemoryTokenStore;
use WhiteMarket\Sdk\Auth\PartnerTokenCredentials;
use WhiteMarket\Sdk\Auth\PartnerTokenProvider;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\TokenStore;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\FrozenClock;
use WhiteMarket\Sdk\Tests\Support\SyntheticJwt;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(PartnerTokenProvider::class)]
#[CoversClass(PartnerTokenCredentials::class)]
#[CoversClass(InMemoryTokenStore::class)]
final class PartnerTokenProviderTest extends TestCase
{
    private const PARTNER_TOKEN = 'synthetic-partner-token-7a1f';

    private const ISSUED = '2026-01-01T00:00:00+00:00';

    #[Test]
    public function exchangesThePartnerTokenWithAuthToken(): void
    {
        $jwt = SyntheticJwt::issuedAt(new \DateTimeImmutable(self::ISSUED), 86_400);
        $http = new FakeHttpClient(FakeHttpClient::authToken($jwt));

        $token = $this->provider($http, new InMemoryTokenStore(), self::ISSUED)->accessToken();

        self::assertSame($jwt, $token->reveal());
        $request = $http->lastRequest();
        self::assertSame(self::PARTNER_TOKEN, $request->getHeaderLine('X-partner-token'));
        self::assertFalse($request->hasHeader('Authorization'), 'auth_token is sent without a bearer token');
        self::assertSame('AuthToken', $http->lastPayload()['operationName'] ?? null);
    }

    #[Test]
    public function storesTheTokenUntilItsExpiry(): void
    {
        $jwt = SyntheticJwt::issuedAt(new \DateTimeImmutable(self::ISSUED), 3600);
        $store = new InMemoryTokenStore();

        $this->provider(new FakeHttpClient(FakeHttpClient::authToken($jwt)), $store, self::ISSUED)->accessToken();

        $stored = $store->get();
        self::assertNotNull($stored);
        self::assertSame($jwt, $stored->token->reveal());
        self::assertSame('2026-01-01T01:00:00+00:00', $stored->expiresAt->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string, bool}> now => whether auth_token is called again
     */
    public static function moments(): iterable
    {
        yield 'right after issue -> cached' => ['2026-01-01T00:00:00+00:00', false];
        yield '1 s before the margin -> cached' => ['2026-01-01T23:54:59+00:00', false];
        yield 'exactly at the margin (5 min before expiry) -> refreshed' => ['2026-01-01T23:55:00+00:00', true];
        yield 'after expiry -> refreshed' => ['2026-01-02T00:00:01+00:00', true];
    }

    #[Test]
    #[DataProvider('moments')]
    public function refreshesFiveMinutesBeforeExpiry(string $now, bool $refreshed): void
    {
        $store = new InMemoryTokenStore();
        $first = SyntheticJwt::issuedAt(new \DateTimeImmutable(self::ISSUED), 86_400, 'first');
        $second = SyntheticJwt::issuedAt(new \DateTimeImmutable($now), 86_400, 'second');
        $this->provider(new FakeHttpClient(FakeHttpClient::authToken($first)), $store, self::ISSUED)->accessToken();
        $http = new FakeHttpClient(FakeHttpClient::authToken($second));

        $token = $this->provider($http, $store, $now)->accessToken();

        self::assertSame($refreshed ? $second : $first, $token->reveal());
        self::assertCount($refreshed ? 1 : 0, $http->requests());
    }

    #[Test]
    public function keepsATokenShorterThanTheMarginLongEnoughNotToExchangeOnEveryCall(): void
    {
        $store = new InMemoryTokenStore();
        $short = SyntheticJwt::issuedAt(new \DateTimeImmutable(self::ISSUED), 60, 'short');
        $this->provider(new FakeHttpClient(FakeHttpClient::authToken($short)), $store, self::ISSUED)->accessToken();
        $http = new FakeHttpClient();

        $token = $this->provider($http, $store, '2026-01-01T00:04:59+00:00')->accessToken();

        self::assertSame($short, $token->reveal(), 'renewed through the 401 path if the server rejects it earlier');
        self::assertSame([], $http->requests());
        self::assertSame('2026-01-01T00:10:00+00:00', $store->get()?->expiresAt->format(\DATE_ATOM));
    }

    #[Test]
    public function reusesATokenAnotherWorkerStored(): void
    {
        $store = new InMemoryTokenStore();
        $jwt = SyntheticJwt::issuedAt(new \DateTimeImmutable(self::ISSUED), 86_400);
        $store->set(new Secret($jwt), new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $http = new FakeHttpClient();

        self::assertSame($jwt, $this->provider($http, $store, self::ISSUED)->accessToken()->reveal());
        self::assertSame([], $http->requests());
    }

    #[Test]
    public function refreshCallsAuthTokenEvenWithAValidToken(): void
    {
        $store = new InMemoryTokenStore();
        $store->set(new Secret('synthetic-old-jwt'), new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $jwt = SyntheticJwt::issuedAt(new \DateTimeImmutable(self::ISSUED), 86_400);
        $http = new FakeHttpClient(FakeHttpClient::authToken($jwt));

        $token = $this->provider($http, $store, self::ISSUED)->refresh();

        self::assertSame($jwt, $token->reveal());
        self::assertCount(1, $http->requests());
        self::assertSame($jwt, $store->get()?->token->reveal());
    }

    #[Test]
    public function aRejectedPartnerTokenIsNotRetried(): void
    {
        $store = new InMemoryTokenStore();
        $http = new FakeHttpClient(FakeHttpClient::status(401, '{"code":401,"message":"Invalid partner token"}'), FakeHttpClient::authToken('synthetic-never-sent'));

        $e = TraceArguments::caught(fn(): Secret => $this->provider($http, $store, self::ISSUED)->accessToken());

        self::assertInstanceOf(AuthenticationException::class, $e);
        self::assertCount(1, $http->requests());
        self::assertNull($store->get());
        self::assertStringNotContainsString(self::PARTNER_TOKEN, $e->getMessage() . TraceArguments::exportedSdkFrames($e));
    }

    /**
     * @return iterable<string, array{string}> an access token that is not a bearer token (RFC 6750 b64token)
     */
    public static function invalidAccessTokens(): iterable
    {
        yield 'LF inside' => ["synthetic-token\nwith-newline"];
        yield 'CR LF inside' => ["synthetic-token\r\nX-Injected: 1"];
        yield 'trailing space' => ['synthetic-token '];
        yield 'leading space' => [' synthetic-token'];
        yield 'space inside' => ['synthetic token'];
        yield 'non-ASCII' => ["synthetic-t\u{00f6}ken"];
        yield 'empty' => [''];
        yield 'only padding' => ['='];
        yield 'padding in the middle' => ['abc=def'];
    }

    #[Test]
    #[DataProvider('invalidAccessTokens')]
    public function rejectsAnAccessTokenThatIsNotAValidBearerTokenBeforeStoringIt(string $token): void
    {
        $store = new InMemoryTokenStore();
        $http = new FakeHttpClient(FakeHttpClient::authToken($token));

        $e = TraceArguments::caught(fn(): Secret => $this->provider($http, $store, self::ISSUED)->accessToken());

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('auth_token.accessToken', $e->path);
        self::assertNull($store->get(), 'nothing is stored');
        if ($token !== '') {
            self::assertStringNotContainsString($token, $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function validAccessTokens(): iterable
    {
        yield 'every b64token character' => ['AZaz09-._~+/'];
        yield 'with padding' => ['abc123=='];
    }

    #[Test]
    #[DataProvider('validAccessTokens')]
    public function acceptsAnAccessTokenThatIsAValidBearerToken(string $token): void
    {
        $store = new InMemoryTokenStore();

        $result = $this->provider(new FakeHttpClient(FakeHttpClient::authToken($token)), $store, self::ISSUED)->accessToken();

        self::assertSame($token, $result->reveal());
        self::assertSame($token, $store->get()?->token->reveal());
    }

    /**
     * @return iterable<string, array{string}> what a shared store, an older SDK or another process may have written
     */
    public static function poisonedStoredTokens(): iterable
    {
        yield 'CR LF header injection' => ["synthetic-jwt\r\nX-Injected: 1"];
        yield 'space and comma' => ['abc def,ghi'];
    }

    #[Test]
    #[DataProvider('poisonedStoredTokens')]
    public function treatsAStoredTokenThatIsNotAValidBearerTokenAsAbsentAndHealsTheStore(string $poisoned): void
    {
        $store = new InMemoryTokenStore();
        $store->set(new Secret($poisoned), new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $jwt = SyntheticJwt::issuedAt(new \DateTimeImmutable(self::ISSUED), 86_400);
        $http = new FakeHttpClient(FakeHttpClient::authToken($jwt));

        $token = $this->provider($http, $store, self::ISSUED)->accessToken();

        self::assertSame($jwt, $token->reveal());
        self::assertCount(1, $http->requests(), 'exactly one auth_token exchange');
        self::assertSame($jwt, $store->get()?->token->reveal(), 'the store holds the fresh token');
    }

    #[Test]
    public function clearsAPoisonedStoredTokenEvenWhenTheExchangeFails(): void
    {
        $store = new InMemoryTokenStore();
        $store->set(new Secret("synthetic-jwt\r\nX-Injected: 1"), new \DateTimeImmutable('2026-01-02T00:00:00+00:00'));
        $http = new FakeHttpClient(FakeHttpClient::status(401, '{"code":401,"message":"Invalid partner token"}'));

        $e = TraceArguments::caught(fn(): Secret => $this->provider($http, $store, self::ISSUED)->accessToken());

        self::assertInstanceOf(AuthenticationException::class, $e);
        self::assertNull($store->get());
    }

    #[Test]
    public function doesNotRevealThePartnerTokenWhenDumped(): void
    {
        $provider = $this->provider(new FakeHttpClient(), new InMemoryTokenStore(), self::ISSUED);

        self::assertStringNotContainsString(self::PARTNER_TOKEN, print_r($provider, true) . var_export($provider, true));
    }

    private function provider(FakeHttpClient $http, TokenStore $store, string $now): PartnerTokenProvider
    {
        $clock = new FrozenClock($now);

        return new PartnerTokenProvider(TestTransport::over($http, $clock), new Secret(self::PARTNER_TOKEN), $store, $clock);
    }
}
