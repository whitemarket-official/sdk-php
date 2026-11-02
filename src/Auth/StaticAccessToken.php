<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * An access token (JWT) the caller obtains and renews (SDK_SPEC 2 `accessToken`). It cannot be refreshed: after a 401
 * {@see refresh()} returns the same token, so the request is not replayed and the 401 reaches the caller.
 *
 * @internal built from the client's `accessToken` option
 */
final readonly class StaticAccessToken implements TokenProvider
{
    public function __construct(#[\SensitiveParameter] private Secret $token) {}

    public function accessToken(): Secret
    {
        return $this->token;
    }

    public function refresh(): Secret
    {
        return $this->token;
    }
}
