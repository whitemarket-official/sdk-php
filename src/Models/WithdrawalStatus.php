<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * State of a withdrawal.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum WithdrawalStatus: string
{
    case Expired = 'EXPIRED';
    case Failed = 'FAILED';
    case New = 'NEW';
    case Processing = 'PROCESSING';
    case Success = 'SUCCESS';
}
