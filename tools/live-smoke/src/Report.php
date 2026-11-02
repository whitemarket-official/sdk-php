<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

/**
 * Markdown report of a run (`var/live-smoke/report.md`, uploaded as a workflow artifact).
 */
final class Report
{
    private function __construct() {}

    /**
     * @param list<StepResult> $results
     */
    public static function render(array $results): string
    {
        $lines = ['# Live smoke report', '', '| Step | Result | ms | Detail |', '| --- | --- | ---: | --- |'];
        foreach ($results as $r) {
            $lines[] = \sprintf('| %s | %s | %d | %s |', self::cell($r->name), $r->status->value, $r->milliseconds, self::cell($r->detail));
        }
        $lines[] = '';
        $lines[] = self::summary($results);

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<StepResult> $results
     */
    public static function summary(array $results): string
    {
        $fail = 0;
        foreach ($results as $r) {
            if ($r->status === StepStatus::Fail) {
                ++$fail;
            }
        }

        return \sprintf('PASS %d, FAIL %d', \count($results) - $fail, $fail);
    }

    /**
     * @param list<StepResult> $results
     */
    public static function exitCode(array $results): int
    {
        foreach ($results as $r) {
            if ($r->status === StepStatus::Fail) {
                return 1;
            }
        }

        return 0;
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n", "\r"], ['\\|', ' ', ' '], $text);
    }
}
