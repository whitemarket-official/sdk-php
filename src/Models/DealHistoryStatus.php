<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Outcome filter of the deal history (`MarketDealHistoryStatus`); not the same as {@see DealStatus}.
 *
 * A value the schema adds later is reachable through a raw `MarkerDealHistorySearchInput` until this enum learns it.
 */
enum DealHistoryStatus: string
{
    case Bought = 'BOUGHT';
    case Cancelled = 'CANCELLED';
    case Completed = 'COMPLETED';
    case Expired = 'EXPIRED';
    case Sold = 'SOLD';
}
