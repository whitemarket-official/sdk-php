<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Selling;

use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;

/**
 * What the seller decides for new listings (SDK_SPEC 3.5): how the item is delivered, how fast the seller promises
 * to deliver and how long the listing stays on the market.
 */
final readonly class ListingOptions
{
    /** Shortest listing lifetime in seconds (SDK_SPEC 3.5, WHITEMARKET_API.md 4). */
    public const MIN_LIFETIME = 60;

    /** Longest listing lifetime in seconds: 365 days (SDK_SPEC 3.5, WHITEMARKET_API.md 4). */
    public const MAX_LIFETIME = 31536000;

    /**
     * @param DeliveryType $deliveryType `Semi` (default) or `Manual`
     * @param ?DeliveryPromise $deliveryPromise the server's default when null
     * @param ?int $lifetime seconds, {@see MIN_LIFETIME}..{@see MAX_LIFETIME}; the server's default (the maximum) when null
     *
     * @throws \InvalidArgumentException `Auto` delivery, or a lifetime outside the range
     */
    public function __construct(
        public DeliveryType $deliveryType = DeliveryType::Semi,
        public ?DeliveryPromise $deliveryPromise = null,
        public ?int $lifetime = null,
    ) {
        if ($deliveryType === DeliveryType::Auto) {
            throw new \InvalidArgumentException('AUTO delivery is reserved by the server and rejected; use SEMI or MANUAL.');
        }
        if ($lifetime !== null && ($lifetime < self::MIN_LIFETIME || $lifetime > self::MAX_LIFETIME)) {
            throw new \InvalidArgumentException(\sprintf('The lifetime must be %d..%d seconds, got %d.', self::MIN_LIFETIME, self::MAX_LIFETIME, $lifetime));
        }
    }
}
