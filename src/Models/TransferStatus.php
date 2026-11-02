<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * State of a transfer between partners.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum TransferStatus: string
{
    case Cancelled = 'CANCELLED';
    case New = 'NEW';
    case Processing = 'PROCESSING';
    case Success = 'SUCCESS';
}
