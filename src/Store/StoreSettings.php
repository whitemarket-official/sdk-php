<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Store;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\PersonStoreInput;
use WhiteMarket\Sdk\Models\StoreAvatar;
use WhiteMarket\Sdk\Models\StoreBanner;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * The settings `store->edit()` changes (SDK_SPEC 3.9, proposal P17). Every parameter is optional; one left out (null) is
 * not sent, so the server keeps its value. There is no client-side validation: the API documents no limits.
 *
 * The multipart `customAvatar` upload of the API is not supported (DEVIATIONS 1.1); `$customAvatar` is the base64
 * encoded image sent as `encodedCustomAvatar`.
 */
final readonly class StoreSettings
{
    /**
     * @param ?string $customAvatar the avatar image, base64 encoded
     */
    public function __construct(
        public ?StoreAvatar $avatar = null,
        public ?StoreBanner $banner = null,
        public ?string $customAvatar = null,
        public ?string $storeName = null,
        public ?bool $isAchievementsPublic = null,
        public ?bool $isFavoritesPublic = null,
        public ?bool $isItemsOnSalePublic = null,
        public ?bool $isStoreNamePublic = null,
        public ?bool $isTradeHistoryPublic = null,
    ) {}

    /**
     * @internal used by {@see StoreApi}
     *
     * @throws \InvalidArgumentException an avatar or banner the schema does not accept
     */
    public function toInput(): PersonStoreInput
    {
        $wire = new WireEncoder();

        return new PersonStoreInput(
            avatar: $wire->enum(Wire\AvatarType::class, $this->avatar),
            banner: $wire->enum(Wire\BannerType::class, $this->banner),
            encodedCustomAvatar: $this->customAvatar,
            isAchievementsPublic: $this->isAchievementsPublic,
            isFavoritesPublic: $this->isFavoritesPublic,
            isItemsOnSalePublic: $this->isItemsOnSalePublic,
            isStoreNamePublic: $this->isStoreNamePublic,
            isTradeHistoryPublic: $this->isTradeHistoryPublic,
            storeName: $this->storeName,
        );
    }
}
