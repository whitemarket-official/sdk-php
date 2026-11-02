<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\Psr16TokenStore;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\StoredToken;
use WhiteMarket\Sdk\Tests\Support\ArrayCache;
use WhiteMarket\Sdk\Tests\Support\FrozenClock;

#[CoversClass(Psr16TokenStore::class)]
#[CoversClass(StoredToken::class)]
final class Psr16TokenStoreTest extends TestCase
{
    private const NOW = '2026-01-01T00:00:00+00:00';

    private const KEY = 'whitemarket.token';

    private const JWT = 'synthetic-jwt-3b8d';

    #[Test]
    public function keepsTheTokenUntilItExpires(): void
    {
        $cache = new ArrayCache(new FrozenClock(self::NOW));
        $store = new Psr16TokenStore($cache, self::KEY, new FrozenClock(self::NOW));

        $store->set(new Secret(self::JWT), new \DateTimeImmutable('2026-01-01T01:00:00+00:00'));

        $stored = $store->get();
        self::assertNotNull($stored);
        self::assertSame(self::JWT, $stored->token->reveal());
        self::assertSame('2026-01-01T01:00:00+00:00', $stored->expiresAt->format(\DATE_ATOM));
        self::assertSame([self::KEY => [['token' => self::JWT, 'expiresAt' => 1_767_229_200], 1_767_229_200]], $cache->entries(), 'the TTL ends at the expiry');
    }

    #[Test]
    public function aWorkerSharingTheCacheReadsTheToken(): void
    {
        $cache = new ArrayCache(new FrozenClock(self::NOW));
        (new Psr16TokenStore($cache, self::KEY, new FrozenClock(self::NOW)))->set(new Secret(self::JWT), new \DateTimeImmutable('2026-01-01T01:00:00+00:00'));

        self::assertSame(self::JWT, (new Psr16TokenStore($cache, self::KEY))->get()?->token->reveal());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function pastExpiries(): iterable
    {
        yield 'expires now' => [self::NOW];
        yield 'expired' => ['2025-12-31T23:59:59+00:00'];
    }

    #[Test]
    #[DataProvider('pastExpiries')]
    public function doesNotKeepAnExpiredToken(string $expiresAt): void
    {
        $cache = new ArrayCache(new FrozenClock(self::NOW));
        $cache->set(self::KEY, ['token' => 'synthetic-older-jwt', 'expiresAt' => 1_767_229_200]);
        $store = new Psr16TokenStore($cache, self::KEY, new FrozenClock(self::NOW));

        $store->set(new Secret(self::JWT), new \DateTimeImmutable($expiresAt));

        self::assertSame([], $cache->entries());
    }

    #[Test]
    public function keepsATokenThatExpiresInOneSecond(): void
    {
        $cache = new ArrayCache(new FrozenClock(self::NOW));

        (new Psr16TokenStore($cache, self::KEY, new FrozenClock(self::NOW)))->set(new Secret(self::JWT), new \DateTimeImmutable('2026-01-01T00:00:01+00:00'));

        self::assertSame(1_767_225_601, $cache->entries()[self::KEY][1] ?? null);
    }

    #[Test]
    public function clearRemovesTheToken(): void
    {
        $cache = new ArrayCache(new FrozenClock(self::NOW));
        $cache->set('other', 'kept');
        $store = new Psr16TokenStore($cache, self::KEY, new FrozenClock(self::NOW));
        $store->set(new Secret(self::JWT), new \DateTimeImmutable('2026-01-01T01:00:00+00:00'));

        $store->clear();

        self::assertNull($store->get());
        self::assertSame(['other'], array_keys($cache->entries()));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function malformedEntries(): iterable
    {
        yield 'a string' => ['synthetic-jwt'];
        yield 'empty array' => [[]];
        yield 'token missing' => [['expiresAt' => 1_767_229_200]];
        yield 'empty token' => [['token' => '', 'expiresAt' => 1_767_229_200]];
        yield 'token not a string' => [['token' => 42, 'expiresAt' => 1_767_229_200]];
        yield 'expiry missing' => [['token' => self::JWT]];
        yield 'expiry as a string' => [['token' => self::JWT, 'expiresAt' => '1767229200']];
    }

    #[Test]
    #[DataProvider('malformedEntries')]
    public function readsAMalformedEntryAsAMiss(mixed $entry): void
    {
        $cache = new ArrayCache();
        $cache->set(self::KEY, $entry);

        self::assertNull((new Psr16TokenStore($cache, self::KEY))->get());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield '65 characters' => [str_repeat('k', 65)];
        yield 'reserved character' => ['whitemarket:token'];
        yield 'trailing line feed' => ["whitemarket.token\n"];
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function rejectsAKeyNotEveryCacheAccepts(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Psr16TokenStore(new ArrayCache(), $key);
    }

    #[Test]
    public function acceptsA64CharacterKey(): void
    {
        $cache = new ArrayCache(new FrozenClock(self::NOW));
        $key = str_repeat('k', 63) . '.';

        (new Psr16TokenStore($cache, $key, new FrozenClock(self::NOW)))->set(new Secret(self::JWT), new \DateTimeImmutable('2026-01-01T01:00:00+00:00'));

        self::assertSame([$key], array_keys($cache->entries()));
    }

    #[Test]
    public function keysDerivedFromPartnerTokensSeparateAccounts(): void
    {
        $cache = new ArrayCache(new FrozenClock(self::NOW));
        $clock = new FrozenClock(self::NOW);
        $first = Psr16TokenStore::forPartnerToken($cache, 'synthetic-partner-a', $clock);
        $sameAccount = Psr16TokenStore::forPartnerToken($cache, new Secret('synthetic-partner-a'), $clock);
        $second = Psr16TokenStore::forPartnerToken($cache, 'synthetic-partner-b', $clock);

        $first->set(new Secret(self::JWT), new \DateTimeImmutable('2026-01-01T01:00:00+00:00'));

        self::assertSame(self::JWT, $sameAccount->get()?->token->reveal());
        self::assertNull($second->get());
        $key = array_key_first($cache->entries());
        self::assertSame('whitemarket_sdk.access_token.' . substr(hash('sha256', 'synthetic-partner-a'), 0, 32), $key);
        self::assertStringNotContainsString('synthetic-partner-a', $key);
    }

    #[Test]
    public function rejectsAnEmptyPartnerToken(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Psr16TokenStore::forPartnerToken(new ArrayCache(), '');
    }
}
