<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * CS2 item wear category.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum Exterior: string
{
    case BattleScared = 'BATTLE_SCARED';
    case FactoryNew = 'FACTORY_NEW';
    case FieldTested = 'FIELD_TESTED';
    case MinimalWear = 'MINIMAL_WEAR';
    case NotPainted = 'NOT_PAINTED';
    case WellWorn = 'WELL_WORN';
}
