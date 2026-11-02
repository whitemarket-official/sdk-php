<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * How several conditions combine.
 *
 * A value the schema adds later is reachable through a raw `MarketProductSearchInput` until this enum learns it.
 */
enum LogicOperand: string
{
    case And = 'AND';
    case Or = 'OR';
}
