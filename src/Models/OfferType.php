<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Kind of a market offer.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum OfferType: string
{
    case Bargain = 'BARGAIN';
}
