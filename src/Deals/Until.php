<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Deals;

use WhiteMarket\Sdk\Models\DealState;
use WhiteMarket\Sdk\Models\DealStatus;

/**
 * Ready-made conditions for `deals->waitFor()` (SDK_SPEC 3.4).
 *
 * Every predicate also matches a FINAL status ({@see DealStatus::isFinal()}: completed, failed or reversed), so a wait
 * never outlives a cancelled, expired or reversed deal. The caller checks the status of the returned state to see how
 * the deal ended. A status this SDK does not know ({@see \WhiteMarket\Sdk\Models\UnknownEnumValue}) matches none of them.
 *
 * `COMPLETED` is a final status, so {@see completed()} and {@see final()} match the same states.
 */
final class Until
{
    private function __construct() {}

    /**
     * The offer was made (`OFFERED`, `BUYER_OFFERED`, `SELLER_OFFERED`, `NEED_CONFIRMATION`), or the deal is already past
     * that: exchanged or final.
     *
     * @return \Closure(DealState): bool
     */
    public static function offered(): \Closure
    {
        $exchangedOrCompleted = self::exchangedOrCompleted();

        return static fn(DealState $state): bool => $exchangedOrCompleted($state)
            || ($state->status instanceof DealStatus && \in_array($state->status, [DealStatus::Offered, DealStatus::BuyerOffered, DealStatus::SellerOffered, DealStatus::NeedConfirmation], true));
    }

    /**
     * Items moved in Steam (`EXCHANGED`) or the deal is final.
     *
     * @return \Closure(DealState): bool
     */
    public static function exchangedOrCompleted(): \Closure
    {
        return static fn(DealState $state): bool => $state->status instanceof DealStatus && ($state->status->isExchanged() || $state->status->isFinal());
    }

    /**
     * The deal reached `COMPLETED`, or any other final status (see the class documentation).
     *
     * @return \Closure(DealState): bool
     */
    public static function completed(): \Closure
    {
        return self::final();
    }

    /**
     * The deal will not change any more: completed, failed or reversed.
     *
     * @return \Closure(DealState): bool
     */
    public static function final(): \Closure
    {
        return static fn(DealState $state): bool => $state->status instanceof DealStatus && $state->status->isFinal();
    }
}
