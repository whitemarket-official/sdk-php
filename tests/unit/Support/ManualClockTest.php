<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Tests\Support\ManualClock;

#[CoversClass(ManualClock::class)]
final class ManualClockTest extends TestCase
{
    /**
     * @return iterable<string, array{float}>
     */
    public static function hugeSleeps(): iterable
    {
        yield '10^13 seconds' => [1e13];
        yield '10^300 seconds' => [1e300];
        yield 'the largest float' => [PHP_FLOAT_MAX];
    }

    #[Test]
    #[DataProvider('hugeSleeps')]
    public function neverGoesBackwardsOnAHugeSleep(float $seconds): void
    {
        $clock = new ManualClock();
        $before = $clock->now();

        $clock->sleep($seconds);

        self::assertGreaterThan($before, $clock->now());
        $clock->sleep(1.0);
        self::assertGreaterThan($before->modify('+1 year'), $clock->now());
    }

    #[Test]
    public function addsFractionsOfASecond(): void
    {
        $clock = new ManualClock('2026-01-01T00:00:00+00:00');

        $clock->sleep(2.25);
        $clock->sleep(0.5);

        self::assertSame('2026-01-01T00:00:02.750000+00:00', $clock->now()->format('Y-m-d\TH:i:s.uP'));
    }
}
