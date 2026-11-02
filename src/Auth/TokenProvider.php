<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

use WhiteMarket\Sdk\Errors\WhiteMarketException;

/**
 * Supplies the access token (JWT) sent as `Authorization: Bearer` (SDK_SPEC 2 `tokenProvider`). After a 401 the SDK
 * calls {@see accessToken()} and then {@see refresh()}, and replays the request once with a token different from the
 * rejected one (SDK_SPEC 1.4); a provider that cannot renew returns the same token and the 401 propagates.
 *
 * Tokens must be RFC 6750 b64token (`[A-Za-z0-9-._~+/]+=*`, which every JWT is). The SDK refuses to send anything else
 * and throws \UnexpectedValueException: that is a bug in the provider, not an API error.
 */
interface TokenProvider
{
    /**
     * A token that is valid as far as the provider knows, renewed when it is about to expire.
     *
     * @throws WhiteMarketException the token could not be obtained
     */
    public function accessToken(): Secret;

    /**
     * Obtains a new token now, even if the current one has not expired.
     *
     * @throws WhiteMarketException the token could not be obtained
     */
    public function refresh(): Secret;
}
