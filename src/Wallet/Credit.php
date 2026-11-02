<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Models\Money;

/**
 * The credit line of the wallet (`wallet.credit()`), read in one request so the three figures are consistent.
 */
final readonly class Credit
{
    /**
     * @param Money $max the most the partner may owe
     * @param Money $used what is owed now
     * @param Money $available what can still be taken
     */
    public function __construct(
        public Money $max,
        public Money $used,
        public Money $available,
    ) {}
}
