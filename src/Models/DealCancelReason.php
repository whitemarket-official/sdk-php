<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Why a deal was cancelled. {@see self::Unknown} is a value the server sends itself; a reason the server adds later
 * arrives as {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum DealCancelReason: string
{
    case BuyerInventoryPrivate = 'BUYER_INVENTORY_PRIVATE';
    case BuyerNotTradeable = 'BUYER_NOT_TRADEABLE';
    case BuyerOrSellerNotTradeable = 'BUYER_OR_SELLER_NOT_TRADEABLE';
    case BuyerSteamCancelled = 'BUYER_STEAM_CANCELLED';
    case BuyerSteamFamilyView = 'BUYER_STEAM_FAMILY_VIEW';
    case BuyerTooManyFailedAttempts = 'BUYER_TOO_MANY_FAILED_ATTEMPTS';
    case BuyerTradeUrlInvalid = 'BUYER_TRADE_URL_INVALID';
    case CancelByAdmin = 'CANCEL_BY_ADMIN';
    case Internal = 'INTERNAL';
    case NoFunds = 'NO_FUNDS';
    case SellerApikeyInvalid = 'SELLER_APIKEY_INVALID';
    case SellerNotTradeable = 'SELLER_NOT_TRADEABLE';
    case SellerSteamCancelled = 'SELLER_STEAM_CANCELLED';
    case SellerSteamFamilyView = 'SELLER_STEAM_FAMILY_VIEW';
    case SellerTradeUrlInvalid = 'SELLER_TRADE_URL_INVALID';
    case SkinUnavailable = 'SKIN_UNAVAILABLE';
    case SteamIssues = 'STEAM_ISSUES';
    case SteamSendingLimitExceed = 'STEAM_SENDING_LIMIT_EXCEED';
    case SteamVacBan = 'STEAM_VAC_BAN';
    case Unknown = 'UNKNOWN';
}
