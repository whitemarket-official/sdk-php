<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * A lot changed (`market_product_edited`): a partial update. Only `id` is always set; every other field is null when
 * the message does not carry it or carries it blank (so null means "unchanged or unknown", not "cleared"). The fields
 * are those of {@see ProductAdded}.
 */
final readonly class ProductEdited implements LiveEvent
{
    /**
     * @param list<array<string, mixed>>|null $stickers
     * @param list<array<string, mixed>>|null $keychains
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $channel,
        public string $id,
        public ?string $appId = null,
        public ?string $assetId = null,
        public ?string $classId = null,
        public ?string $instanceId = null,
        public ?Money $price = null,
        public ?string $marketHashName = null,
        public ?string $inspectLink = null,
        public DeliveryType|UnknownEnumValue|null $deliveryType = null,
        public DeliveryPromise|UnknownEnumValue|null $deliveryPromise = null,
        public ?Decimal $float = null,
        public ?string $paintIndex = null,
        public ?string $paintSeed = null,
        public ?array $stickers = null,
        public ?array $keychains = null,
        public array $extra = [],
    ) {}
}
