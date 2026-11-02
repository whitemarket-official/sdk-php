<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Cryptocurrency a deposit or withdrawal was made in.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum CryptoCurrency: string
{
    case Btc = 'BTC';
    case Doge = 'DOGE';
    case Eth = 'ETH';
    case Ltc = 'LTC';
    case Sol = 'SOL';
    case Usdc = 'USDC';
    case Usdt = 'USDT';
    case Wbt = 'WBT';
}
