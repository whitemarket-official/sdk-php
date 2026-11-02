<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * The game (Steam app) a listing belongs to. The schema's `UNKNOWN` value is not a filter value and is not listed.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum App: string
{
    case Csgo = 'CSGO';
    case Dota2 = 'DOTA2';
    case Tf2 = 'TF2';
}
