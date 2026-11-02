<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * The default {@see TokenStore}: the token lives as long as this object, so each process obtains its own.
 */
final class InMemoryTokenStore implements TokenStore
{
    private ?StoredToken $token = null;

    public function get(): ?StoredToken
    {
        return $this->token;
    }

    public function set(#[\SensitiveParameter] Secret $token, \DateTimeImmutable $expiresAt): void
    {
        $this->token = new StoredToken($token, $expiresAt);
    }

    public function clear(): void
    {
        $this->token = null;
    }
}
