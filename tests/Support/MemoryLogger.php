<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that keeps every record, for redaction tests.
 */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function records(): array
    {
        return $this->records;
    }

    /**
     * Every record as one string, to assert that a secret appears nowhere.
     */
    public function dump(): string
    {
        return var_export($this->records, true);
    }
}
