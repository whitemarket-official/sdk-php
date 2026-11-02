<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * A listing on the market (SDK_SPEC 3.2, 3.5).
 */
final readonly class Product
{
    /**
     * @param string $slug the listing's id in market URLs; `market.get()` accepts it as well as {@see $id}
     * @param bool $isOwner the listing belongs to the authenticated account
     * @param ?InventoryItem $item null when the server sends an item type this SDK version does not know
     */
    public function __construct(
        public string $id,
        public string $slug,
        public Money $price,
        public \DateTimeImmutable $createdAt,
        public bool $isOwner,
        public DeliveryType|UnknownEnumValue $deliveryType,
        public DeliveryPromise|UnknownEnumValue $deliveryPromise,
        public ?InventoryItem $item,
    ) {}
}
