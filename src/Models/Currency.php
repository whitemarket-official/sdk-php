<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Currency of a {@see Money} amount. The market operates in USD; a currency the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum Currency: string
{
    case Usd = 'USD';
}
