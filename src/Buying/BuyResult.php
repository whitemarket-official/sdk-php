<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Buying;

use WhiteMarket\Sdk\Models\Deal;

/**
 * The outcome of a purchase (SDK_SPEC 3.3).
 */
final readonly class BuyResult
{
    /**
     * @param Deal $deal the deal as the server reports it
     * @param string $customId the id the purchase was sent with: yours, or the generated one
     * @param bool $reconciled true when `market_buy` failed ambiguously and the deal was found by looking it up
     */
    public function __construct(
        #[\SensitiveParameter]
        public Deal $deal,
        public string $customId,
        public bool $reconciled,
    ) {}
}
