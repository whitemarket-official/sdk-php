<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Transport\SystemSleeper;

#[CoversClass(SystemSleeper::class)]
final class SystemSleeperTest extends TestCase
{
    /**
     * @return iterable<string, array{float, list<int>}>
     */
    public static function finiteDurations(): iterable
    {
        yield 'zero -> no sleep' => [0.0, []];
        yield 'negative -> no sleep' => [-1.0, []];
        yield 'NaN -> no sleep' => [NAN, []];
        yield 'under half a microsecond -> no sleep' => [0.0000004, []];
        yield 'exactly half a microsecond rounds up' => [0.0000005, [1]];
        yield 'one microsecond' => [0.000001, [1]];
        yield 'one and four tenths of a microsecond rounds down' => [0.0000014, [1]];
        yield 'one and a half microseconds rounds up' => [0.0000015, [2]];
        yield 'one and a half seconds' => [1.5, [1_500_000]];
        yield 'exactly one chunk' => [1000.0, [SystemSleeper::MAX_CHUNK_MICROSECONDS]];
        yield 'one chunk and one microsecond' => [1000.000001, [SystemSleeper::MAX_CHUNK_MICROSECONDS, 1]];
    }

    /**
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('finiteDurations')]
    public function splitsDurationIntoMicrosecondChunks(float $seconds, array $expected): void
    {
        self::assertSame($expected, iterator_to_array(SystemSleeper::chunks($seconds), false));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function absurdDurations(): iterable
    {
        yield '1e13 seconds (overflows an int in microseconds)' => [1e13];
        yield 'INF' => [INF];
    }

    #[Test]
    #[DataProvider('absurdDurations')]
    public function neverYieldsNegativeOrOversizedChunkForAbsurdDurations(float $seconds): void
    {
        $seen = 0;
        foreach (SystemSleeper::chunks($seconds) as $chunk) {
            self::assertGreaterThan(0, $chunk);
            self::assertLessThanOrEqual(SystemSleeper::MAX_CHUNK_MICROSECONDS, $chunk);
            if (++$seen === 1000) {
                break;
            }
        }

        self::assertSame(1000, $seen, 'an absurd duration keeps sleeping instead of ending early');
    }

    #[Test]
    public function sleepsForNonPositiveDurationWithoutThrowing(): void
    {
        (new SystemSleeper())->sleep(-1.0);
        (new SystemSleeper())->sleep(NAN);

        $this->expectNotToPerformAssertions();
    }
}
