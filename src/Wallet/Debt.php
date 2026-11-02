<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Models\Money;

/**
 * Credit taken from the marketplace (`wallet.takeCredit()`).
 */
final readonly class Debt
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public Money $amount,
    ) {}
}
