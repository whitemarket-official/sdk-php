<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Inventory;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\PersonInventorySearchInput;
use WhiteMarket\Sdk\Market\ProductSort;
use WhiteMarket\Sdk\Models\Color;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Exterior;
use WhiteMarket\Sdk\Models\ItemCategory;
use WhiteMarket\Sdk\Models\ItemSubcategory;
use WhiteMarket\Sdk\Models\LogicOperand;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\Rarity;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * Conditions of an inventory list (SDK_SPEC 3.6): every parameter is optional and named, and the ones given are
 * combined with AND. Deprecated fields of the API are not exposed; pass a raw `PersonInventorySearchInput` to
 * `inventory->list()` for anything this class does not cover.
 */
final readonly class InventoryFilter
{
    /**
     * @param list<Exterior>|null $exteriors
     * @param list<Rarity>|null $rarities
     * @param list<Color>|null $colors
     * @param ?Decimal $floatFrom lower wear bound
     * @param ?Decimal $floatTo upper wear bound
     * @param ?float $fadeFrom lower Fade percentage (the API takes a Float here)
     * @param ?float $fadeTo upper Fade percentage
     * @param ?bool $hasStickers only items with (true) or without (false) stickers
     * @param ?bool $hasCharm only items with (true) or without (false) a charm
     * @param list<string>|null $stickerNames sticker names, see `market->stickerSuggestions()`
     * @param ?int $pattern paint seed
     * @param ?ProductSort $sort the order of the list (the API shares the product sort fields)
     */
    public function __construct(
        public ?string $nameHash = null,
        public ?string $name = null,
        public ?bool $nameStrict = null,
        public ?ItemCategory $category = null,
        public ?ItemSubcategory $subcategory = null,
        public ?array $rarities = null,
        public ?array $exteriors = null,
        public ?array $colors = null,
        public ?Phase $phase = null,
        public ?Decimal $floatFrom = null,
        public ?Decimal $floatTo = null,
        public ?float $fadeFrom = null,
        public ?float $fadeTo = null,
        public ?int $fadeRankFrom = null,
        public ?int $fadeRankTo = null,
        public ?bool $statTrak = null,
        public ?bool $souvenir = null,
        public ?bool $hasStickers = null,
        public ?bool $hasCharm = null,
        public ?array $stickerNames = null,
        public ?LogicOperand $stickerNamesOperand = null,
        public ?int $pattern = null,
        public ?string $collection = null,
        public ?ProductSort $sort = null,
    ) {}

    /**
     * @internal used by {@see InventoryApi}
     */
    public function toInput(): PersonInventorySearchInput
    {
        $wire = new WireEncoder();

        return new PersonInventorySearchInput(
            colorEnum: $wire->enumList(Wire\CatalogColor::class, $this->colors),
            csgoCharm: $this->hasCharm,
            csgoCollection: $this->collection,
            csgoExteriorEnum: $wire->enumList(Wire\CSGOExterior::class, $this->exteriors),
            csgoFadeFrom: $this->fadeFrom,
            csgoFadeRankFrom: $this->fadeRankFrom,
            csgoFadeRankTo: $this->fadeRankTo,
            csgoFadeTo: $this->fadeTo,
            csgoFloatFrom: $this->floatFrom?->toString(),
            csgoFloatTo: $this->floatTo?->toString(),
            csgoItemCategoryEnum: $wire->enum(Wire\CSGOCategory::class, $this->category),
            csgoItemSubcategoryEnum: $wire->enum(Wire\CSGOSubcategory::class, $this->subcategory),
            csgoPattern: $this->pattern,
            csgoPhaseEnum: $wire->enum(Wire\CSGOPhase::class, $this->phase),
            csgoRarityEnum: $wire->enumList(Wire\CSGORarity::class, $this->rarities),
            csgoSouvenir: $this->souvenir,
            csgoStatTrak: $this->statTrak,
            csgoStickerNames: $this->stickerNames,
            csgoStickerNamesOperand: $wire->enum(Wire\LogicOperand::class, $this->stickerNamesOperand),
            csgoStickers: $this->hasStickers,
            name: $this->name,
            nameHash: $this->nameHash,
            nameStrict: $this->nameStrict,
            sort: $this->sort?->toInput(),
        );
    }
}
