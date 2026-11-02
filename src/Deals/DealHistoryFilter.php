<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Deals;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\MarkerDealHistorySearchInput;
use WhiteMarket\Sdk\Models\Color;
use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\DealHistoryStatus;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Exterior;
use WhiteMarket\Sdk\Models\LogicOperand;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\Rarity;
use WhiteMarket\Sdk\Models\StickerFilm;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * Conditions of a deal history search (SDK_SPEC 3.4): every parameter is optional and named, and the ones given are
 * combined with AND. The item conditions are named and typed as in {@see \WhiteMarket\Sdk\Market\ProductFilter}.
 * Deprecated fields of the API are not exposed; pass a raw `MarkerDealHistorySearchInput` to `deals->history()` for
 * anything this class does not cover.
 */
final readonly class DealHistoryFilter
{
    /**
     * @param ?DealAction $action only deals where the caller bought (Buy) or sold (Sell)
     * @param ?DealHistoryStatus $status only deals that ended this way
     * @param list<string>|null $assetIds Steam asset ids (64-bit, kept as strings)
     * @param ?\DateTimeInterface $createdFrom deal created at or after
     * @param ?\DateTimeInterface $createdTo deal created at or before
     * @param ?Money $priceFrom lower price bound, inclusive
     * @param ?Money $priceTo upper price bound, inclusive
     * @param ?Decimal $floatFrom lower wear bound
     * @param ?Decimal $floatTo upper wear bound
     * @param list<Rarity>|null $rarities
     * @param list<Exterior>|null $exteriors
     * @param list<Color>|null $colors
     * @param ?bool $hasStickers only items with (true) or without (false) stickers
     * @param ?bool $hasCharm only items with (true) or without (false) a charm
     * @param list<string>|null $stickerNames sticker names, see `market->stickerSuggestions()`
     * @param list<string|null>|null $stickerNamesBySlot sticker name per slot, null for "any sticker" in that slot
     * @param ?int $pattern paint seed
     * @param ?bool $onlyForOrders only deals made through buy orders
     *
     * @throws \InvalidArgumentException a price whose currency is not one this SDK knows
     */
    public function __construct(
        public ?DealAction $action = null,
        public ?DealHistoryStatus $status = null,
        public ?array $assetIds = null,
        public ?\DateTimeInterface $createdFrom = null,
        public ?\DateTimeInterface $createdTo = null,
        public ?Money $priceFrom = null,
        public ?Money $priceTo = null,
        public ?string $nameHash = null,
        public ?string $name = null,
        public ?bool $nameStrict = null,
        public ?Decimal $floatFrom = null,
        public ?Decimal $floatTo = null,
        public ?Phase $phase = null,
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
        public ?bool $onlyForOrders = null,
        public ?DealHistorySort $sort = null,
    ) {
        (new WireEncoder())->assertSendableAll($priceFrom, $priceTo);
    }

    /**
     * @internal used by {@see DealsApi}
     */
    public function toInput(): MarkerDealHistorySearchInput
    {
        $wire = new WireEncoder();

        return new MarkerDealHistorySearchInput(
            action: $wire->enum(Wire\MarketDealActionType::class, $this->action),
            assetIds: $this->assetIds,
            colorEnum: $wire->enumList(Wire\CatalogColor::class, $this->colors),
            createdAt: $wire->dateRange($this->createdFrom, $this->createdTo),
            csgoCharm: $this->hasCharm,
            csgoCollection: $this->collection,
            csgoExteriorEnum: $wire->enumList(Wire\CSGOExterior::class, $this->exteriors),
            csgoFloatFrom: $this->floatFrom?->toString(),
            csgoFloatTo: $this->floatTo?->toString(),
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
            name: $this->name,
            nameHash: $this->nameHash,
            nameStrict: $this->nameStrict,
            onlyForOrders: $this->onlyForOrders,
            price: $wire->moneyRange($this->priceFrom, $this->priceTo),
            sort: $this->sort?->toInput(),
            status: $wire->enum(Wire\MarketDealHistoryStatus::class, $this->status),
        );
    }
}
