<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Field the wallet transactions are sorted by.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum TransactionSortField: string
{
    case Amount = 'AMOUNT';
    case CreatedAt = 'CREATED_AT';
}
