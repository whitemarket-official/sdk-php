<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * How fast the seller promises to deliver. A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum DeliveryPromise: string
{
    case Minutes10 = 'MINUTES10';
    case Hour1 = 'HOUR1';
    case Hours12 = 'HOURS12';
    case Hours24 = 'HOURS24';
}
