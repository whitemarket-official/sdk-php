<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Export\Rows\RowSource;

/**
 * The growth of the PHP memory peak while a callback runs, for the tests of "one row in memory" (SDK_SPEC 3.10).
 */
final class PeakMemory
{
    private function __construct() {}

    /**
     * @param-immediately-invoked-callable $run
     *
     * @param \Closure(): void $run
     */
    public static function growth(\Closure $run): int
    {
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $run();

        return memory_get_peak_usage() - $before;
    }

    /**
     * Pulls every row without keeping any, so only what the source itself holds counts.
     *
     * @param-immediately-invoked-callable $open
     *
     * @param \Closure(): RowSource $open
     */
    public static function growthOfReading(\Closure $open): int
    {
        return self::growth(static function () use ($open): void {
            $rows = $open();
            while ($rows->next()) {
                $rows->row();
            }
        });
    }
}
