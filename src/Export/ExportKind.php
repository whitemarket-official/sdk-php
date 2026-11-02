<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

/**
 * Which export file: the cheapest lot per item, or every lot.
 */
enum ExportKind: string
{
    case Prices = 'prices';
    case Products = 'products';
}
