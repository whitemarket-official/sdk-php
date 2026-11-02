<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * State of a wallet transaction.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum TransactionStatus: string
{
    case Canceled = 'CANCELED';
    case HoldIncome = 'HOLD_INCOME';
    case HoldOutcome = 'HOLD_OUTCOME';
    case Success = 'SUCCESS';
    case SuccessHold = 'SUCCESS_HOLD';
}
