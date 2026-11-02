<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Kind of fee to filter wallet transactions by.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum TransactionFeeType: string
{
    case Bonus = 'BONUS';
    case Deposit = 'DEPOSIT';
    case Internal = 'INTERNAL';
    case MarketProduct = 'MARKET_PRODUCT';
    case Withdrawal = 'WITHDRAWAL';
}
