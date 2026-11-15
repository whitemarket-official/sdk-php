<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * Elapsed time that a change of the system clock cannot move, injectable so waits with a deadline are testable.
 * The origin is arbitrary: only differences between two readings mean anything.
 *
 * @internal injected into {@see \WhiteMarket\Sdk\Deals\DealsApi} (its constructor is @internal); not part of the public API
 */
interface MonotonicClock
{
    /**
     * @return float seconds since an arbitrary fixed point; never decreases
     */
    public function seconds(): float;
}
