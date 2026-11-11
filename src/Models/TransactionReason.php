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
    case InstantExchange = 'INSTANT_EXCHANGE';
    case InstantExchangeHoldReleaseIn = 'INSTANT_EXCHANGE_HOLD_RELEASE_IN';
    case InstantExchangeHoldReleaseOut = 'INSTANT_EXCHANGE_HOLD_RELEASE_OUT';
    case InstantExchangeRefundIn = 'INSTANT_EXCHANGE_REFUND_IN';
    case InstantExchangeRefundOut = 'INSTANT_EXCHANGE_REFUND_OUT';
    case InstantExchangeTransferIn = 'INSTANT_EXCHANGE_TRANSFER_IN';
    case InstantExchangeTransferOut = 'INSTANT_EXCHANGE_TRANSFER_OUT';
    case Internal = 'INTERNAL';
    case MarketOfferHold = 'MARKET_OFFER_HOLD';
    case MarketProduct = 'MARKET_PRODUCT';
    case ReversePenalty = 'REVERSE_PENALTY';
    case ReverseRefund = 'REVERSE_REFUND';
    case Transfer = 'TRANSFER';
    case Withdrawal = 'WITHDRAWAL';
    case WriteOff = 'WRITE_OFF';
}
