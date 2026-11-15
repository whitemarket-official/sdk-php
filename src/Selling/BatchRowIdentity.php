<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Selling;

/**
 * Who the product of a `*Many` row says it is, read without decoding any money or wear value, so a product that cannot
 * be decoded is still checked against the item requested at its position (SDK_SPEC 3.5).
 *
 * @internal used by {@see SellingApi}; not part of the public API
 */
final readonly class BatchRowIdentity
{
    /**
     * @param string $id id of the listing, live or archived
     * @param bool $archived true for an archived listing (`MarketProductHistory`), false for a live one
     * @param ?string $itemId inventory item id of a live listing; null without an inventory item
     * @param ?string $assetId asset id of a live listing's item (64-bit, kept as a string); null without an inventory item
     */
    public function __construct(
        public string $id,
        public bool $archived,
        public ?string $itemId,
        public ?string $assetId,
    ) {}
}
