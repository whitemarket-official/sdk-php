<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

/**
 * Outcome of one smoke step. `$detail` is fixed text or an exception class with a GraphQL category: never an
 * exception message, response data, id, balance or URL.
 */
final readonly class StepResult
{
    public function __construct(
        public string $name,
        public StepStatus $status,
        public int $milliseconds,
        public string $detail = '',
    ) {}
}
