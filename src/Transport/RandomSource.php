<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * Source of the backoff jitter, injectable for deterministic tests.
 */
interface RandomSource
{
    /**
     * A uniformly distributed number in [0, 1).
     */
    public function fraction(): float;
}
