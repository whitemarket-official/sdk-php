<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

use Psr\SimpleCache\CacheInterface;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\SystemClock;

/**
 * A {@see TokenStore} in a PSR-16 cache, for workers that share one token (SDK_SPEC 2). The entry holds the token in
 * plain text and expires with it. A malformed entry reads as a miss; failures of the cache itself propagate.
 */
final readonly class Psr16TokenStore implements TokenStore
{
    /** The characters and length every PSR-16 implementation must accept. */
    private const KEY = '/^[A-Za-z0-9_.]{1,64}$/D';

    private const KEY_PREFIX = 'whitemarket_sdk.access_token.';

    /**
     * @param string $key one key per Partner Token; never the token itself (see {@see forPartnerToken()})
     */
    public function __construct(
        private CacheInterface $cache,
        private string $key,
        private Clock $clock = new SystemClock(),
    ) {
        if (preg_match(self::KEY, $key) !== 1) {
            throw new \InvalidArgumentException('The cache key must be 1 to 64 characters of A-Z, a-z, 0-9, "_" and ".".');
        }
    }

    /**
     * A store whose key is derived from a hash of the Partner Token, so accounts sharing a cache never share a token.
     */
    public static function forPartnerToken(
        CacheInterface $cache,
        #[\SensitiveParameter]
        Secret|string $partnerToken,
        Clock $clock = new SystemClock(),
    ): self {
        $token = $partnerToken instanceof Secret ? $partnerToken->reveal() : $partnerToken;
        if ($token === '') {
            throw new \InvalidArgumentException('The Partner Token must not be empty.');
        }

        return new self($cache, self::KEY_PREFIX . substr(hash('sha256', $token), 0, 32), $clock);
    }

    public function get(): ?StoredToken
    {
        $entry = $this->cache->get($this->key);
        if (!\is_array($entry)) {
            return null;
        }
        $token = $entry['token'] ?? null;
        $expiresAt = $entry['expiresAt'] ?? null;
        if (!\is_string($token) || $token === '' || !\is_int($expiresAt)) {
            return null;
        }

        return new StoredToken(new Secret($token), new \DateTimeImmutable('@' . $expiresAt));
    }

    public function set(#[\SensitiveParameter] Secret $token, \DateTimeImmutable $expiresAt): void
    {
        $ttl = $expiresAt->getTimestamp() - $this->clock->now()->getTimestamp();
        if ($ttl <= 0) {
            // PSR-16 deletes on a TTL <= 0; done explicitly so no implementation keeps an expired token forever.
            $this->cache->delete($this->key);

            return;
        }
        $this->cache->set($this->key, ['token' => $token->reveal(), 'expiresAt' => $expiresAt->getTimestamp()], $ttl);
    }

    public function clear(): void
    {
        $this->cache->delete($this->key);
    }
}
