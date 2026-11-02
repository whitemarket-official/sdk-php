<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Buying;

/**
 * How the SDK looks a purchase up after an ambiguous `market_buy` failure (SDK_SPEC 2 / 3.3, WHITEMARKET_API.md 6:
 * "wait 10-20 seconds"): wait `delay`, then up to `attempts` times look the deal up by its `customId` (in progress,
 * then archived), waiting `interval` after each attempt. All durations are in seconds.
 */
final readonly class BuyReconciliation
{
    /**
     * @param float $delay wait before the first lookup, 10 to 20 inclusive
     * @param int $attempts number of lookup rounds, at least 1
     * @param float $interval wait after each round, finite and not negative
     *
     * @throws \InvalidArgumentException a value out of its range
     */
    public function __construct(
        public float $delay = 15.0,
        public int $attempts = 3,
        public float $interval = 5.0,
    ) {
        if (!is_finite($delay) || $delay < 10.0 || $delay > 20.0) {
            throw new \InvalidArgumentException('delay must be between 10 and 20 seconds.');
        }
        if ($attempts < 1) {
            throw new \InvalidArgumentException('attempts must be at least 1.');
        }
        if (!is_finite($interval) || $interval < 0.0) {
            throw new \InvalidArgumentException('interval must be a finite number of seconds, not negative.');
        }
    }
}
