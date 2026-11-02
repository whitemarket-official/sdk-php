<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * A listing that left the market: sold (the product of a deal), removed or expired.
 */
final readonly class ProductHistory
{
    /**
     * @param ?InventoryItem $item null when the server sends an item type this SDK version does not know
     */
    public function __construct(
        public string $id,
        public ProductStatus|UnknownEnumValue $status,
        public Money $price,
        public \DateTimeImmutable $archivedAt,
        public DeliveryType|UnknownEnumValue $deliveryType,
        public string $assetId,
        public string $classId,
        public string $instanceId,
        public ?InventoryItem $item,
    ) {}
}
