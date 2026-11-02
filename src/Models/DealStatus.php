<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Status of a deal, grouped as in WHITEMARKET_API.md section 4. A status the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue}, which belongs to none of the groups (CLAUDE.md rule 5).
 */
enum DealStatus: string
{
    case Hold = 'HOLD';
    case Initiated = 'INITIATED';
    /** Absent from the public schema; treated as in progress if the server sends it (WHITEMARKET_API.md 4). */
    case Queued = 'QUEUED';
    case SellerSendingSteamOffer = 'SELLER_SENDING_STEAM_OFFER';
    case WaitingSteamQueue = 'WAITING_STEAM_QUEUE';
    case BuyerCancelRequested = 'BUYER_CANCEL_REQUESTED';
    case Offered = 'OFFERED';
    case BuyerOffered = 'BUYER_OFFERED';
    case SellerOffered = 'SELLER_OFFERED';
    case NeedConfirmation = 'NEED_CONFIRMATION';
    /** Items moved in Steam; with Trade Protection the deal waits for `settlementTimeAt` and can still be reversed. */
    case Exchanged = 'EXCHANGED';
    case Completed = 'COMPLETED';
    case Cancelled = 'CANCELLED';
    case BuyerCancelled = 'BUYER_CANCELLED';
    case SellerCancelled = 'SELLER_CANCELLED';
    case Expired = 'EXPIRED';
    case BuyerExpired = 'BUYER_EXPIRED';
    case SellerExpired = 'SELLER_EXPIRED';
    /** Transitional, always ends in an expired status; already counted as failed and final. */
    case Expiring = 'EXPIRING';
    case SteamRollback = 'STEAM_ROLLBACK';
    case BuyerSteamRollback = 'BUYER_STEAM_ROLLBACK';
    case SellerSteamRollback = 'SELLER_STEAM_ROLLBACK';

    /**
     * Still moving towards an exchange: keep waiting. {@see self::Exchanged} is not in progress, it is its own stage.
     */
    public function isInProgress(): bool
    {
        return $this->stage() === 'inProgress';
    }

    public function isExchanged(): bool
    {
        return $this->stage() === 'exchanged';
    }

    /**
     * Funds settled.
     */
    public function isSuccessful(): bool
    {
        return $this->stage() === 'successful';
    }

    /**
     * No trade happened.
     */
    public function isFailed(): bool
    {
        return $this->stage() === 'failed';
    }

    /**
     * Steam reverted the trade after the exchange.
     */
    public function isReversed(): bool
    {
        return $this->stage() === 'reversed';
    }

    /**
     * The deal will not change any more: successful, failed or reversed.
     */
    public function isFinal(): bool
    {
        return $this->isSuccessful() || $this->isFailed() || $this->isReversed();
    }

    /**
     * The group of WHITEMARKET_API.md section 4. No default arm: a new case must be classified here.
     *
     * @return 'inProgress'|'exchanged'|'successful'|'failed'|'reversed'
     */
    private function stage(): string
    {
        return match ($this) {
            self::Hold, self::Initiated, self::Queued, self::SellerSendingSteamOffer, self::WaitingSteamQueue,
            self::BuyerCancelRequested, self::Offered, self::BuyerOffered, self::SellerOffered,
            self::NeedConfirmation => 'inProgress',
            self::Exchanged => 'exchanged',
            self::Completed => 'successful',
            self::Cancelled, self::BuyerCancelled, self::SellerCancelled, self::Expired, self::BuyerExpired,
            self::SellerExpired, self::Expiring => 'failed',
            self::SteamRollback, self::BuyerSteamRollback, self::SellerSteamRollback => 'reversed',
        };
    }
}
