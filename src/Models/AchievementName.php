<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Which achievement a store entry is. A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum AchievementName: string
{
    case ComboWombo = 'COMBO_WOMBO';
    case EntryFrag = 'ENTRY_FRAG';
    case FebruaryMarch24EliteTrader = 'FEBRUARY_MARCH_24_ELITE_TRADER';
    case FirstSell = 'FIRST_SELL';
    case FirstSteps = 'FIRST_STEPS';
    case ForceBuy = 'FORCE_BUY';
    case FullBuy = 'FULL_BUY';
    case June23EliteTrader = 'JUNE_23_ELITE_TRADER';
    case KnifeKill = 'KNIFE_KILL';
    case MobileDefuseKit = 'MOBILE_DEFUSE_KIT';
    case ProTrader = 'PRO_TRADER';
    case SafeHands = 'SAFE_HANDS';
    case September23EliteTrader = 'SEPTEMBER_23_ELITE_TRADER';
    case VerifiedUser = 'VERIFIED_USER';
}
