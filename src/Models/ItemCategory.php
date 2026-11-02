<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * CS2 item category.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum ItemCategory: string
{
    case Agent = 'AGENT';
    case Case = 'CASE';
    case Gloves = 'GLOVES';
    case Heavy = 'HEAVY';
    case Knives = 'KNIVES';
    case Other = 'OTHER';
    case Pistols = 'PISTOLS';
    case Rifles = 'RIFLES';
    case Smgs = 'SMGS';
    case SniperRifles = 'SNIPER_RIFLES';
    case Stickers = 'STICKERS';
}
