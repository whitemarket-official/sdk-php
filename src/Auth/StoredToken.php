<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * An access token and the moment it expires, as kept by a {@see TokenStore}.
 */
final readonly class StoredToken
{
    public function __construct(
        #[\SensitiveParameter]
        public Secret $token,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
