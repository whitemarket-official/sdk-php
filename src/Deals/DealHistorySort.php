<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Deals;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\MarketDealHistorySortInput;
use WhiteMarket\Sdk\Models\DealHistorySortField;
use WhiteMarket\Sdk\Models\SortDirection;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * How the deal history is ordered (see {@see DealHistoryFilter::$sort}).
 */
final readonly class DealHistorySort
{
    public function __construct(
        public DealHistorySortField $field,
        public SortDirection $direction = SortDirection::Asc,
    ) {}

    /**
     * @internal used by {@see DealHistoryFilter}
     */
    public function toInput(): MarketDealHistorySortInput
    {
        $wire = new WireEncoder();

        return new MarketDealHistorySortInput(
            $wire->enum(Wire\MarketDealHistorySortField::class, $this->field),
            $wire->enum(Wire\SortType::class, $this->direction),
        );
    }
}
