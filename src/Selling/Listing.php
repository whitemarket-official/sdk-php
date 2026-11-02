<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Selling;

use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * One item to put on sale in {@see SellingApi::listMany()}.
 */
final readonly class Listing
{
    /**
     * @param string $item inventory id (UUID) or 64-bit Steam asset id (digits, kept as a string)
     *
     * @throws \InvalidArgumentException `$item` is neither, or the currency of `$price` is not one this SDK knows
     */
    public function __construct(
        public string $item,
        public Money $price,
    ) {
        ItemReference::inventoryOrAsset($item);
        (new WireEncoder())->assertSendable($price);
    }
}
