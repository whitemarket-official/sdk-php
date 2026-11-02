<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\Sleeper;

/**
 * A clock that moves only when the code under test sleeps: `sleep(5.0)` advances `now()` by five seconds and returns
 * at once. Records the delays. Lets a wait-with-timeout test run the whole loop deterministically.
 */
final class ManualClock implements Clock, Sleeper
{
    /**
     * Beyond this many seconds (about 3000 years) the clock stays at one far-future instant: a huge sleep never wraps.
     */
    private const MAX_ELAPSED_SECONDS = 1e11;

    private float $elapsedSeconds = 0.0;

    /** @var list<float> */
    private array $delays = [];

    private readonly \DateTimeImmutable $start;

    public function __construct(string $start = '2026-01-01T00:00:00+00:00')
    {
        $this->start = new \DateTimeImmutable($start);
    }

    public function now(): \DateTimeImmutable
    {
        $whole = (int) floor($this->elapsedSeconds);
        $microseconds = (int) round(($this->elapsedSeconds - $whole) * 1_000_000);
        $now = $this->start->modify(\sprintf('+%d seconds +%d microseconds', $whole, $microseconds));

        return $now instanceof \DateTimeImmutable ? $now : throw new \LogicException('Cannot advance the clock');
    }

    public function sleep(float $seconds): void
    {
        $this->delays[] = $seconds;
        $this->elapsedSeconds = min(self::MAX_ELAPSED_SECONDS, $this->elapsedSeconds + $seconds);
    }

    /**
     * Moves time forward without recording a delay: time that passes while the code under test waits for I/O.
     */
    public function advance(float $seconds): void
    {
        $this->elapsedSeconds = min(self::MAX_ELAPSED_SECONDS, $this->elapsedSeconds + $seconds);
    }

    /**
     * @return list<float>
     */
    public function delays(): array
    {
        return $this->delays;
    }
}
