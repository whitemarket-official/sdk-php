<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\GraphQL;

/**
 * Runs one GraphQL operation and returns its `data` object. Implementations own the HTTP request, auth, retry
 * and error mapping (ADR 0002); the generated layer only builds documents and decodes results.
 */
interface OperationExecutor
{
    /**
     * @param array<string, mixed> $variables
     *
     * @return array<mixed> the `data` member of the response
     */
    public function execute(
        string $document,
        string $operationName,
        OperationType $type,
        #[\SensitiveParameter]
        array $variables,
    ): array;
}
