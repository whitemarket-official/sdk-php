<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Which side of the deal history to read: deals where the caller bought or where the caller sold.
 *
 * A value the schema adds later is reachable through a raw `MarkerDealHistorySearchInput` until this enum learns it.
 */
enum DealAction: string
{
    case Buy = 'BUY';
    case Sell = 'SELL';
}
