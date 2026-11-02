<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * A condition parameter of a store achievement. A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum AchievementCondition: string
{
    case BuyGloves = 'CONDITION_BUY_GLOVES';
    case BuyKnife = 'CONDITION_BUY_KNIFE';
    case BuyPistol = 'CONDITION_BUY_PISTOL';
    case BuySmgOrRifle = 'CONDITION_BUY_SMG_OR_RIFLE';
    case FulfilledTradeAmount = 'CONDITION_FULFILLED_TRADE_AMOUNT';
    case TradeAmount = 'CONDITION_TRADE_AMOUNT';
}
