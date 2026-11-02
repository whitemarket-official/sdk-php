<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * The `X-partner-token` header of `auth_token` (WHITEMARKET_API.md 3). A 401 here means the Partner Token itself is
 * rejected, so there is nothing to replay with.
 *
 * @internal used by {@see PartnerTokenProvider}
 */
final readonly class PartnerTokenCredentials implements Credentials
{
    public function __construct(#[\SensitiveParameter] private Secret $partnerToken) {}

    public function headers(string $operationName): array
    {
        return ['X-partner-token' => $this->partnerToken];
    }

    public function headersAfterRejection(#[\SensitiveParameter] array $rejected): ?array
    {
        return null;
    }
}
