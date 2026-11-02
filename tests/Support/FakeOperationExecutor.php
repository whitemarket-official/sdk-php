<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\GraphQL\OperationExecutor;
use WhiteMarket\Sdk\GraphQL\OperationType;

/**
 * Outer boundary of the generated layer for tests: returns queued `data` objects and records every call.
 * Replaced by the fake PSR-18 client once the HTTP transport exists (P2).
 */
final class FakeOperationExecutor implements OperationExecutor
{
    /** @var list<array<mixed>> */
    private array $responses;

    /** @var list<array{document: string, operationName: string, type: OperationType, variables: array<string, mixed>}> */
    private array $calls = [];

    /**
     * @param array<mixed> ...$responses `data` objects, returned in order
     */
    public function __construct(array ...$responses)
    {
        $this->responses = array_values($responses);
    }

    public function execute(string $document, string $operationName, OperationType $type, #[\SensitiveParameter] array $variables): array
    {
        $this->calls[] = ['document' => $document, 'operationName' => $operationName, 'type' => $type, 'variables' => $variables];
        $response = array_shift($this->responses);
        if ($response === null) {
            throw new \LogicException(\sprintf('No response queued for %s', $operationName));
        }

        return $response;
    }

    /**
     * @return list<array{document: string, operationName: string, type: OperationType, variables: array<string, mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * @return array{document: string, operationName: string, type: OperationType, variables: array<string, mixed>}
     */
    public function lastCall(): array
    {
        return $this->calls[\count($this->calls) - 1] ?? throw new \LogicException('No operation was executed');
    }
}
