<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * `hrtime()` in seconds.
 *
 * @internal the default {@see MonotonicClock} of {@see \WhiteMarket\Sdk\Deals\DealsApi}; not part of the public API
 */
final class SystemMonotonicClock implements MonotonicClock
{
    public function seconds(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
