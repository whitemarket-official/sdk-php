<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

use WhiteMarket\Sdk\Models\Money;

/**
 * A lot left the market (`market_product_removed`): sold, delisted or expired. The fields are those of {@see ProductAdded}.
 */
final readonly class ProductRemoved implements LiveEvent
{
    /**
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
        public array $extra = [],
    ) {}
}
