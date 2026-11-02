<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

/**
 * The delivery-time window of an export (SDK_SPEC 3.10): only lots whose seller promises delivery within it. The value
 * is the suffix of the file name.
 */
enum ExportWindow: string
{
    case All = '';
    case Minutes10 = '.10min';
    case Hour1 = '.1hour';
    case Hours12 = '.12hours';
    case Hours24 = '.24hours';
}
