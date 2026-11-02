<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Buying;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Buying\BuyReconciliation;

#[CoversClass(BuyReconciliation::class)]
final class BuyReconciliationTest extends TestCase
{
    #[Test]
    public function defaultsFollowTheSpec(): void
    {
        $reconciliation = new BuyReconciliation();

        self::assertSame([15.0, 3, 5.0], [$reconciliation->delay, $reconciliation->attempts, $reconciliation->interval]);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function acceptedDelays(): iterable
    {
        yield 'lower bound' => [10.0];
        yield 'upper bound' => [20.0];
        yield 'inside' => [12.5];
    }

    #[Test]
    #[DataProvider('acceptedDelays')]
    public function acceptsADelayOfTenToTwentySeconds(float $delay): void
    {
        self::assertSame($delay, (new BuyReconciliation(delay: $delay))->delay);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function rejectedDelays(): iterable
    {
        yield 'just below 10' => [9.99];
        yield 'just above 20' => [20.01];
        yield 'zero' => [0.0];
        yield 'negative' => [-15.0];
        yield 'NaN' => [\NAN];
        yield 'infinity' => [\INF];
    }

    #[Test]
    #[DataProvider('rejectedDelays')]
    public function rejectsADelayOutsideTenToTwentySeconds(float $delay): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('delay must be between 10 and 20 seconds.'));

        new BuyReconciliation(delay: $delay);
    }

    #[Test]
    public function acceptsOneAttempt(): void
    {
        self::assertSame(1, (new BuyReconciliation(attempts: 1))->attempts);
    }

    #[Test]
    public function rejectsZeroAttempts(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('attempts must be at least 1.'));

        new BuyReconciliation(attempts: 0);
    }

    #[Test]
    public function acceptsAZeroInterval(): void
    {
        self::assertSame(0.0, (new BuyReconciliation(interval: 0.0))->interval);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function rejectedIntervals(): iterable
    {
        yield 'just below zero' => [-0.01];
        yield 'infinity' => [\INF];
        yield 'NaN' => [\NAN];
    }

    #[Test]
    #[DataProvider('rejectedIntervals')]
    public function rejectsAnIntervalThatIsNegativeOrNotFinite(float $interval): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('interval must be a finite number of seconds, not negative.'));

        new BuyReconciliation(interval: $interval);
    }
}
