<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Why a wallet transaction happened.
 *
 * A value the schema adds later is read as an {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum TransactionReason: string
{
    case Bonus = 'BONUS';
    case Deposit = 'DEPOSIT';
    case GiftCardPurchase = 'GIFT_CARD_PURCHASE';
    case Internal = 'INTERNAL';
    case MarketOfferHold = 'MARKET_OFFER_HOLD';
    case MarketProduct = 'MARKET_PRODUCT';
    case ReversePenalty = 'REVERSE_PENALTY';
    case ReverseRefund = 'REVERSE_REFUND';
    case Transfer = 'TRANSFER';
    case Withdrawal = 'WITHDRAWAL';
    case WriteOff = 'WRITE_OFF';
}
