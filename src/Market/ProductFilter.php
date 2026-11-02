<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Market;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\MarketProductSearchInput;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\Color;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\Exterior;
use WhiteMarket\Sdk\Models\ItemCategory;
use WhiteMarket\Sdk\Models\ItemSubcategory;
use WhiteMarket\Sdk\Models\LogicOperand;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\OfferType;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\Rarity;
use WhiteMarket\Sdk\Models\StickerFilm;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * Conditions of a product search (SDK_SPEC 3.2): every parameter is optional and named, and the ones given are
 * combined with AND. Deprecated fields of the API are not exposed; pass a raw `MarketProductSearchInput` to
 * `market->search()` for anything this class does not cover.
 */
final readonly class ProductFilter
{
    /**
     * @param list<string>|null $nameHashes market hash names (exact)
     * @param list<string>|null $names names to match
     * @param list<string>|null $ids product ids
     * @param ?Money $priceFrom lower price bound, inclusive
     * @param ?Money $priceTo upper price bound, inclusive
     * @param ?Decimal $floatFrom lower wear bound
     * @param ?Decimal $floatTo upper wear bound
     * @param ?float $fadeFrom lower Fade percentage (the API takes a Float here)
     * @param ?float $fadeTo upper Fade percentage
     * @param list<ItemCategory>|null $excludeCategories
     * @param list<ItemSubcategory>|null $excludeSubcategories
     * @param list<Rarity>|null $rarities
     * @param list<Exterior>|null $exteriors
     * @param list<Color>|null $colors
     * @param ?bool $hasStickers only items with (true) or without (false) stickers
     * @param ?bool $hasCharm only items with (true) or without (false) a charm
     * @param list<string>|null $stickerNames sticker names, see `market->stickerSuggestions()`
     * @param list<string|null>|null $stickerNamesBySlot sticker name per slot, null for "any sticker" in that slot
     * @param ?int $pattern paint seed
     * @param ?\DateTimeInterface $createdFrom listed at or after
     * @param ?\DateTimeInterface $createdTo listed at or before
     *
     * @throws \InvalidArgumentException a price whose currency is not one this SDK knows
     */
    public function __construct(
        public ?App $app = null,
        public ?string $nameHash = null,
        public ?array $nameHashes = null,
        public ?string $name = null,
        public ?array $names = null,
        public ?bool $nameStrict = null,
        public ?array $ids = null,
        public ?Money $priceFrom = null,
        public ?Money $priceTo = null,
        public ?Decimal $floatFrom = null,
        public ?Decimal $floatTo = null,
        public ?float $fadeFrom = null,
        public ?float $fadeTo = null,
        public ?int $fadeRankFrom = null,
        public ?int $fadeRankTo = null,
        public ?Phase $phase = null,
        public ?ItemCategory $category = null,
        public ?ItemSubcategory $subcategory = null,
        public ?array $excludeCategories = null,
        public ?array $excludeSubcategories = null,
        public ?array $rarities = null,
        public ?array $exteriors = null,
        public ?array $colors = null,
        public ?bool $statTrak = null,
        public ?bool $souvenir = null,
        public ?bool $hasStickers = null,
        public ?bool $hasCharm = null,
        public ?array $stickerNames = null,
        public ?array $stickerNamesBySlot = null,
        public ?LogicOperand $stickerNamesOperand = null,
        public ?StickerFilm $stickerFilm = null,
        public ?int $stickerPlayerId = null,
        public ?int $stickerTeamId = null,
        public ?int $stickerTournamentId = null,
        public ?int $pattern = null,
        public ?string $collection = null,
        public ?string $skin = null,
        public ?DeliveryType $deliveryType = null,
        public ?DeliveryPromise $deliveryPromise = null,
        public ?OfferType $offerType = null,
        public ?string $storeSlug = null,
        public ?ProductSort $sort = null,
        public ?\DateTimeInterface $createdFrom = null,
        public ?\DateTimeInterface $createdTo = null,
        public ?bool $distinctValues = null,
        public ?bool $onlyPromo = null,
        public ?bool $popular = null,
        public ?bool $weaponAndGear = null,
        public ?bool $special = null,
        public ?bool $superDeal = null,
    ) {
        (new WireEncoder())->assertSendableAll($priceFrom, $priceTo);
    }

    /**
     * @internal used by {@see MarketApi}
     */
    public function toInput(): MarketProductSearchInput
    {
        $wire = new WireEncoder();

        return new MarketProductSearchInput(
            appId: $wire->enum(Wire\SteamApp::class, $this->app),
            colorEnum: $wire->enumList(Wire\CatalogColor::class, $this->colors),
            createdAt: $wire->dateRange($this->createdFrom, $this->createdTo),
            csgoCharm: $this->hasCharm,
            csgoCollection: $this->collection,
            csgoExteriorEnum: $wire->enumList(Wire\CSGOExterior::class, $this->exteriors),
            csgoFadeFrom: $this->fadeFrom,
            csgoFadeRankFrom: $this->fadeRankFrom,
            csgoFadeRankTo: $this->fadeRankTo,
            csgoFadeTo: $this->fadeTo,
            csgoFloatFrom: $this->floatFrom?->toString(),
            csgoFloatTo: $this->floatTo?->toString(),
            csgoItemCategoriesExclude: $wire->enumList(Wire\CSGOCategory::class, $this->excludeCategories),
            csgoItemCategoryEnum: $wire->enum(Wire\CSGOCategory::class, $this->category),
            csgoItemSkin: $this->skin,
            csgoItemSubcategoriesExclude: $wire->enumList(Wire\CSGOSubcategory::class, $this->excludeSubcategories),
            csgoItemSubcategoryEnum: $wire->enum(Wire\CSGOSubcategory::class, $this->subcategory),
            csgoPattern: $this->pattern,
            csgoPhaseEnum: $wire->enum(Wire\CSGOPhase::class, $this->phase),
            csgoRarityEnum: $wire->enumList(Wire\CSGORarity::class, $this->rarities),
            csgoSouvenir: $this->souvenir,
            csgoStatTrak: $this->statTrak,
            csgoStickerFilm: $wire->enum(Wire\CSGOStickerFilm::class, $this->stickerFilm),
            csgoStickerNames: $this->stickerNames,
            csgoStickerNamesBySlot: $this->stickerNamesBySlot,
            csgoStickerNamesOperand: $wire->enum(Wire\LogicOperand::class, $this->stickerNamesOperand),
            csgoStickerPlayer: $this->stickerPlayerId,
            csgoStickerTeam: $this->stickerTeamId,
            csgoStickerTournament: $this->stickerTournamentId,
            csgoStickers: $this->hasStickers,
            deliveryPromise: $wire->enum(Wire\DeliveryPromise::class, $this->deliveryPromise),
            deliveryType: $wire->enum(Wire\DeliveryType::class, $this->deliveryType),
            distinctValues: $this->distinctValues,
            ids: $this->ids,
            isPopular: $this->popular,
            isWeaponAndGear: $this->weaponAndGear,
            name: $this->name,
            nameHash: $this->nameHash,
            nameStrict: $this->nameStrict,
            names: $this->names,
            namesHash: $this->nameHashes,
            offerType: $wire->enum(Wire\MarketOfferType::class, $this->offerType),
            onlyPromo: $this->onlyPromo,
            price: $wire->moneyRange($this->priceFrom, $this->priceTo),
            sort: $this->sort?->toInput(),
            special: $this->special,
            storeSlug: $this->storeSlug,
            superDeal: $this->superDeal,
        );
    }
}
