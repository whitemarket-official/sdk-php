<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

final class SystemSleeper implements Sleeper
{
    /**
     * Longest single `usleep()` call: PHP passes the value on as an `unsigned int`, so it must stay below 2^32.
     *
     * @internal
     */
    public const MAX_CHUNK_MICROSECONDS = 1_000_000_000;

    public function sleep(float $seconds): void
    {
        foreach (self::chunks($seconds) as $microseconds) {
            usleep($microseconds);
        }
    }

    /**
     * Splits a duration into positive `usleep()` arguments; non-positive, NaN and sub-microsecond durations give none,
     * an absurdly large or infinite one gives chunks practically without end instead of overflowing an int.
     *
     * @internal
     *
     * @return \Generator<int, int>
     */
    public static function chunks(float $seconds): \Generator
    {
        $remaining = $seconds * 1_000_000;
        while ($remaining >= 0.5) {
            $chunk = min($remaining, (float) self::MAX_CHUNK_MICROSECONDS);
            yield (int) round($chunk);
            $remaining -= $chunk;
        }
    }
}
