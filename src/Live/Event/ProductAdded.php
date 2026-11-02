<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * A lot appeared on the market (`market_product_added`). Every id is a digit string (`appId` is Steam's `"730"`, not the
 * schema enum); `price` is USD (live messages carry no currency, owner decision O1). `inspectLink`, `float`, `paintIndex`
 * and `paintSeed` are null where the server sends them blank (a later {@see ProductEdited} brings them). A delivery
 * value this SDK version does not know arrives as {@see UnknownEnumValue}. `stickers` and `keychains` are the raw
 * decoded lists. `extra` holds the keys of the message this SDK version does not map.
 */
final readonly class ProductAdded implements LiveEvent
{
    /**
     * @param list<array<string, mixed>> $stickers
     * @param list<array<string, mixed>> $keychains
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $channel,
        public string $id,
        public string $appId,
        public string $assetId,
        public string $classId,
        public string $instanceId,
        public Money $price,
        public string $marketHashName,
        public ?string $inspectLink,
        public DeliveryType|UnknownEnumValue|null $deliveryType,
        public DeliveryPromise|UnknownEnumValue|null $deliveryPromise,
        public ?Decimal $float,
        public ?string $paintIndex,
        public ?string $paintSeed,
        public array $stickers = [],
        public array $keychains = [],
        public array $extra = [],
    ) {}
}
