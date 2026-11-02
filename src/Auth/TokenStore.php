<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * Where the access token obtained with the Partner Token is cached (SDK_SPEC 2 `tokenStore`). Workers that share a
 * store share one token instead of each calling `auth_token`, which is rate limited. One store (or one key of a
 * shared cache) per Partner Token: a store shared by two accounts hands one account's token to the other.
 */
interface TokenStore
{
    public function get(): ?StoredToken;

    public function set(#[\SensitiveParameter] Secret $token, \DateTimeImmutable $expiresAt): void;

    public function clear(): void;
}
