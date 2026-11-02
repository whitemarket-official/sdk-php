<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Transport\Clock;

/**
 * A clock that always shows the same time.
 */
final readonly class FrozenClock implements Clock
{
    private \DateTimeImmutable $now;

    public function __construct(string $now = '2026-01-01T00:00:00+00:00')
    {
        $this->now = new \DateTimeImmutable($now);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->now;
    }
}
