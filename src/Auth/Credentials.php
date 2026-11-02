<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

use WhiteMarket\Sdk\Errors\WhiteMarketException;

/**
 * Supplies the authentication headers of one GraphQL request (SDK_SPEC 1.3): `Authorization: Bearer <JWT>`, or
 * `X-partner-token` for `auth_token` only. Internal: users configure auth through the client options and
 * {@see TokenProvider} (SDK_SPEC 2, ADR 0003).
 *
 * @internal
 */
interface Credentials
{
    /**
     * May call the API (a token exchange) before it returns, so it can throw what any request throws.
     *
     * @return array<string, Secret> header name => header value
     *
     * @throws WhiteMarketException the token could not be obtained
     */
    public function headers(string $operationName): array;

    /**
     * Called once after the server answered 401 to a request sent with `$rejected`.
     *
     * @param array<string, Secret> $rejected the headers of the rejected request
     *
     * @return array<string, Secret>|null headers to replay the request with once, or null to let the 401 propagate
     *
     * @throws WhiteMarketException a new token could not be obtained
     */
    public function headersAfterRejection(#[\SensitiveParameter] array $rejected): ?array;
}
