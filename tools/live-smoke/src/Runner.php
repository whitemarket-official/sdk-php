<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

/**
 * Times a step and turns its outcome into a {@see StepResult}.
 */
final class Runner
{
    private function __construct() {}

    /**
     * @param-immediately-invoked-callable $step
     * @param \Closure(): ?string $step returns null on success, or a fixed failure text (never data from the API)
     */
    public static function run(string $name, \Closure $step): StepResult
    {
        $start = microtime(true);
        try {
            $failure = $step();
        } catch (\Throwable $e) {
            return Classifier::classify($name, $e, self::since($start));
        }

        return $failure === null
            ? new StepResult($name, StepStatus::Pass, self::since($start))
            : new StepResult($name, StepStatus::Fail, self::since($start), $failure);
    }

    private static function since(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }
}
