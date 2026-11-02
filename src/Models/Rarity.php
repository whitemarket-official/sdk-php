<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * CS2 item rarity.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum Rarity: string
{
    case Ancient = 'ANCIENT';
    case AncientCharacter = 'ANCIENT_CHARACTER';
    case AncientWeapon = 'ANCIENT_WEAPON';
    case Common = 'COMMON';
    case CommonWeapon = 'COMMON_WEAPON';
    case Contraband = 'CONTRABAND';
    case DefaultWeapon = 'DEFAULT_WEAPON';
    case Legendary = 'LEGENDARY';
    case LegendaryCharacter = 'LEGENDARY_CHARACTER';
    case LegendaryWeapon = 'LEGENDARY_WEAPON';
    case Mythical = 'MYTHICAL';
    case MythicalCharacter = 'MYTHICAL_CHARACTER';
    case MythicalWeapon = 'MYTHICAL_WEAPON';
    case Rare = 'RARE';
    case RareCharacter = 'RARE_CHARACTER';
    case RareWeapon = 'RARE_WEAPON';
    case UncommonWeapon = 'UNCOMMON_WEAPON';
}
