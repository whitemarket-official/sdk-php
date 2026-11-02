<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Field the deal history is sorted by.
 *
 * A value the schema adds later is reachable through a raw `MarkerDealHistorySearchInput` until this enum learns it.
 */
enum DealHistorySortField: string
{
    case CreatedAt = 'CREATED_AT';
    case Price = 'PRICE';
}
