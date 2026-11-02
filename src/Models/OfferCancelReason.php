<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Why a seller cancels sending a Steam offer (`deals->cancelSendingOffer()`, `MarketDealExternalCancelReason`).
 * Choose the true reason: a wrong one can lead to sanctions (WHITEMARKET_API.md 7).
 *
 * A value the schema adds later is reachable through the generated client until this enum learns it.
 */
enum OfferCancelReason: string
{
    case BuyerInventoryPrivate = 'BUYER_INVENTORY_PRIVATE';
    case BuyerNotTradeable = 'BUYER_NOT_TRADEABLE';
    case BuyerSteamFamilyView = 'BUYER_STEAM_FAMILY_VIEW';
    case SellerNotTradeable = 'SELLER_NOT_TRADEABLE';
    case SkinUnavailable = 'SKIN_UNAVAILABLE';
    case SteamIssues = 'STEAM_ISSUES';
    case SteamIssues15 = 'STEAM_ISSUES_15';
    case SteamSendingLimitExceed = 'STEAM_SENDING_LIMIT_EXCEED';
    case SteamVacBan = 'STEAM_VAC_BAN';
    case Unknown = 'UNKNOWN';
}
