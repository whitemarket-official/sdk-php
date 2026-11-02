<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Blockchain network of a crypto deposit or withdrawal.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum CryptoNetwork: string
{
    case Arbitrum = 'ARBITRUM';
    case Base = 'BASE';
    case Bep20 = 'BEP20';
    case Btc = 'BTC';
    case Cchain = 'CCHAIN';
    case Doge = 'DOGE';
    case Erc20 = 'ERC20';
    case Ltc = 'LTC';
    case Near = 'NEAR';
    case Optimism = 'OPTIMISM';
    case Polygon = 'POLYGON';
    case Scroll = 'SCROLL';
    case Sol = 'SOL';
    case Trc20 = 'TRC20';
    case Whitechain = 'WHITECHAIN';
}
