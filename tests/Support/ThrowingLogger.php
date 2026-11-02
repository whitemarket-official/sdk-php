<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that records the calls it received and then throws, like a handler that cannot open its file.
 */
final class ThrowingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: array<mixed>}> */
    private array $calls = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->calls[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];

        throw new \RuntimeException('log failed');
    }

    /**
     * @return list<array{level: mixed, message: string, context: array<mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }
}
