<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * The interface language of an account. A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum Locale: string
{
    case DeDe = 'DE_DE';
    case EnUs = 'EN_US';
    case EsEs = 'ES_ES';
    case FrFr = 'FR_FR';
    case PlPl = 'PL_PL';
    case PtPt = 'PT_PT';
    case RuRu = 'RU_RU';
    case TrTr = 'TR_TR';
    case UkUa = 'UK_UA';
}
