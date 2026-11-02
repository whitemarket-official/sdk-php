<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Payment method of a wallet transaction.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum WalletMethod: string
{
    case Airwallex = 'AIRWALLEX';
    case BankCard = 'BANK_CARD';
    case C4s = 'C4S';
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
    case Tipalti = 'TIPALTI';
    case Whitebit = 'WHITEBIT';
    case WhitebitCode = 'WHITEBIT_CODE';
    case Whitepay = 'WHITEPAY';
    case Zen = 'ZEN';
}
