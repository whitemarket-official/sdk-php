<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Market;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\MarketProductSortInput;
use WhiteMarket\Sdk\Models\ProductSortField;
use WhiteMarket\Sdk\Models\SortDirection;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * How a product search is ordered (see {@see ProductFilter::$sort}); also the sort of an inventory list.
 */
final readonly class ProductSort
{
    public function __construct(
        public ProductSortField $field,
        public SortDirection $direction = SortDirection::Asc,
    ) {}

    /**
     * @internal used by {@see ProductFilter} and {@see \WhiteMarket\Sdk\Inventory\InventoryFilter}
     */
    public function toInput(): MarketProductSortInput
    {
        $wire = new WireEncoder();

        return new MarketProductSortInput(
            $wire->enum(Wire\MarketProductSortField::class, $this->field),
            $wire->enum(Wire\SortType::class, $this->direction),
        );
    }
}
