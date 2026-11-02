<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Payment provider of a deposit or withdrawal.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum PaymentProvider: string
{
    case Airwallex = 'AIRWALLEX';
    case BankCard = 'BANK_CARD';
    case C4s = 'C4S';
    case Debt = 'DEBT';
    case Difmark = 'DIFMARK';
    case Driffle = 'DRIFFLE';
    case Eneba = 'ENEBA';
    case Esea = 'ESEA';
    case G2a = 'G2A';
    case Gameseal = 'GAMESEAL';
    case Internal = 'INTERNAL';
    case Kinguin = 'KINGUIN';
    case Likecard = 'LIKECARD';
    case Mercuryo = 'MERCURYO';
    case Nuvei = 'NUVEI';
    case Offgamers = 'OFFGAMERS';
    case Payop = 'PAYOP';
    case Paypal = 'PAYPAL';
    case Paysera = 'PAYSERA';
    case Seagm = 'SEAGM';
    case Sepa = 'SEPA';
    case Skinsback = 'SKINSBACK';
    case Solidgate = 'SOLIDGATE';
    case Test = 'TEST';
    case Tipalti = 'TIPALTI';
    case Whitebit = 'WHITEBIT';
    case Whitepay = 'WHITEPAY';
    case Wmdrop = 'WMDROP';
    case Zen = 'ZEN';
}
