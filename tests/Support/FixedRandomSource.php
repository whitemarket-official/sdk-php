<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Transport\RandomSource;

/**
 * Jitter source that always returns the same fraction.
 */
final readonly class FixedRandomSource implements RandomSource
{
    public function __construct(private float $fraction) {}

    public function fraction(): float
    {
        return $this->fraction;
    }
}
