<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Field a product list is sorted by.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum ProductSortField: string
{
    case Created = 'CREATED';
    case DistinctValuesCount = 'DISTINCT_VALUES_COUNT';
    case Float = 'FLOAT';
    case Name = 'NAME';
    case OnSale = 'ON_SALE';
    case Popularity = 'POPULARITY';
    case Price = 'PRICE';
    case PriceAbsDiff = 'PRICE_ABS_DIFF';
    case PriceDiff = 'PRICE_DIFF';
    case PriceRatioDiff = 'PRICE_RATIO_DIFF';
    case ProductPrice = 'PRODUCT_PRICE';
}
