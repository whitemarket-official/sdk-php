<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Sticker finish.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum StickerFilm: string
{
    case Foil = 'FOIL';
    case Glitter = 'GLITTER';
    case Gold = 'GOLD';
    case Holo = 'HOLO';
    case Lenticular = 'LENTICULAR';
}
