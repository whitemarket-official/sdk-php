<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\GeneratedClient;

/**
 * `$client->auth` (SDK_SPEC 3.1): the access token the client sends and a connectivity check.
 */
final readonly class AuthApi
{
    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private TokenProvider $provider,
        private GeneratedClient $api,
    ) {}

    /**
     * The access token (JWT) the next request will carry: the cached one while it is valid, otherwise a new one.
     *
     * @throws WhiteMarketException the token could not be obtained
     */
    public function accessToken(): Secret
    {
        return $this->provider->accessToken();
    }

    /**
     * Obtains a new access token now (`auth_token` with a Partner Token). With a fixed `accessToken` there is nothing
     * to renew: the same token is returned.
     *
     * @throws WhiteMarketException the token could not be obtained
     */
    public function refresh(): Secret
    {
        return $this->provider->refresh();
    }

    /**
     * Sends `test_ping` with the access token: succeeds when the API is reachable and accepts the token.
     *
     * @return string the server's answer
     *
     * @throws WhiteMarketException the request failed
     */
    public function ping(): string
    {
        return $this->api->testPing();
    }
}
