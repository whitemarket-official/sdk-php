<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\ItemCategory;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * One row of the products export: one lot on the market (SDK_SPEC 3.10). Ids are digit strings: the class and asset
 * ids are JSON numbers beyond 2^53 in the file. `paintIndex` and `paintSeed` are strings, as in {@see PriceRow}, and
 * null where the item has none. `float` is null for items without a float. `inspectLink` is null where the file has
 * null or an empty string. A category, delivery type or delivery promise this SDK version does not know arrives as
 * {@see UnknownEnumValue} with the raw file value; `type`, `subtype` and `phase` are the raw file values. `extra` holds
 * every key of the row this SDK version does not know, as decoded (a new server field is never an error).
 */
final readonly class ProductRow
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $productId,
        public string $marketHashName,
        public Money $price,
        public ItemCategory|UnknownEnumValue $category,
        public ?string $type,
        public ?string $subtype,
        public string $classId,
        public string $instanceId,
        public string $assetId,
        public ?Decimal $float,
        public ?string $paintIndex,
        public ?string $paintSeed,
        public ?string $phase,
        public DeliveryType|UnknownEnumValue|null $deliveryType,
        public DeliveryPromise|UnknownEnumValue|null $deliveryPromise,
        public ?string $inspectLink,
        public array $extra = [],
    ) {}
}
