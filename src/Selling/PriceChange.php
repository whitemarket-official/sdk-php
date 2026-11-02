<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Selling;

use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * One new price in {@see SellingApi::repriceMany()}.
 */
final readonly class PriceChange
{
    /**
     * @param string $product listing id (UUID) or 64-bit Steam asset id (digits, kept as a string)
     *
     * @throws \InvalidArgumentException `$product` is neither, or the currency of `$price` is not one this SDK knows
     */
    public function __construct(
        public string $product,
        public Money $price,
    ) {
        ItemReference::productOrAsset($product);
        (new WireEncoder())->assertSendable($price);
    }
}
