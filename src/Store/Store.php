<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Store;

use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\StoreAvatar;
use WhiteMarket\Sdk\Models\StoreBanner;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * The partner's own store (`store_my`, and the result of `store_edit`; SDK_SPEC 3.9).
 */
final readonly class Store
{
    /**
     * @param ?string $customAvatarUrl the URL of the uploaded avatar, as sent
     * @param list<Achievement> $achievements empty when the server sends none
     */
    public function __construct(
        public ?string $id,
        public ?string $slug,
        public string $storeName,
        public StoreAvatar|UnknownEnumValue|null $avatar,
        public StoreBanner|UnknownEnumValue|null $banner,
        public ?string $customAvatarUrl,
        public ?string $steamAvatar,
        public DeliveryPromise|UnknownEnumValue|null $deliveryPromise,
        public bool $isTopSeller,
        public bool $isKycVerified,
        public bool $isAchievementsPublic,
        public bool $isFavoritesPublic,
        public bool $isItemsOnSalePublic,
        public bool $isStoreNamePublic,
        public bool $isTradeHistoryPublic,
        public ?int $level,
        public ?int $points,
        public ?int $leftPoints,
        public ?int $years,
        public \DateTimeImmutable $registeredAt,
        public array $achievements,
    ) {}
}
