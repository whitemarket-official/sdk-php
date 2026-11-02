<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * `market_buy` failed ambiguously (it may or may not have run) and reconciliation did not find the deal (SDK_SPEC 1.5
 * `PurchaseOutcomeUnknownError`, 3.3). The purchase may exist: do not buy again blindly, look the deal up later with
 * `$customId`. {@see getPrevious()} is the `market_buy` failure; `$lastLookupError` is the most recent lookup failure that
 * is not a {@see NotFoundException} (a throwable that is not a WhiteMarketException counts as telling; a lookup that failed says more than an absent deal), or the most recent
 * {@see NotFoundException} when every lookup found nothing; null when no lookup ran.
 */
final class PurchaseOutcomeUnknownException extends WhiteMarketException
{
    public function __construct(
        public readonly string $customId,
        \Throwable $cause,
        public readonly ?\Throwable $lastLookupError = null,
    ) {
        parent::__construct(
            \sprintf('Purchase outcome unknown for customId "%s": market_buy failed ambiguously and the deal was not found by reconciliation. Look it up later with this customId.', $customId),
            0,
            $cause,
        );
    }
}
