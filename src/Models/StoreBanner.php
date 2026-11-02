<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * The banner a store shows (`PersonStore.banner`, `StoreSettings::$banner`). A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum StoreBanner: string
{
    case Banner1 = 'BANNER_1';
    case Banner2 = 'BANNER_2';
    case Banner3 = 'BANNER_3';
    case Banner4 = 'BANNER_4';
    case Banner5 = 'BANNER_5';
    case Banner6 = 'BANNER_6';
    case Banner7 = 'BANNER_7';
    case Banner8 = 'BANNER_8';
}
