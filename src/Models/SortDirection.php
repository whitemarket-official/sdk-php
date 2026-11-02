<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Sort direction.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum SortDirection: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
