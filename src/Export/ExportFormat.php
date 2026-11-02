<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

enum ExportFormat: string
{
    case Json = 'json';
    case Csv = 'csv';
}
