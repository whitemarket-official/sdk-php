<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Catalog color of an item.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum Color: string
{
    case Black = 'BLACK';
    case Blue = 'BLUE';
    case Brown = 'BROWN';
    case Cyan = 'CYAN';
    case Gray = 'GRAY';
    case Green = 'GREEN';
    case Orange = 'ORANGE';
    case Pink = 'PINK';
    case Purple = 'PURPLE';
    case Red = 'RED';
    case Unknown = 'UNKNOWN';
    case White = 'WHITE';
    case Yellow = 'YELLOW';
}
