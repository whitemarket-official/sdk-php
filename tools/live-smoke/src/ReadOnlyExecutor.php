<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

use WhiteMarket\Sdk\GraphQL\OperationExecutor;
use WhiteMarket\Sdk\GraphQL\OperationType;

/**
 * The live smoke runs queries only (SDK_SPEC 6.4). Wraps the executor of the smoke's GeneratedClient and throws before
 * anything is sent unless the operation is declared a query AND its document is a query. Both are checked because they
 * come from two independent places of the generated class (`TYPE` and `DOCUMENT`).
 *
 * The one allowed mutation, `auth_token`, does not pass here: the SDK's token provider owns its own executor.
 */
final readonly class ReadOnlyExecutor implements OperationExecutor
{
    public function __construct(private OperationExecutor $inner) {}

    public function execute(
        string $document,
        string $operationName,
        OperationType $type,
        #[\SensitiveParameter]
        array $variables,
    ): array {
        if ($type !== OperationType::Query || preg_match('/\A\s*query\b/', $document) !== 1) {
            throw new ReadOnlyViolation(\sprintf('The live smoke refuses to run %s: it is not a query.', preg_replace('/[^A-Za-z0-9_]/', '?', $operationName)));
        }

        return $this->inner->execute($document, $operationName, $type, $variables);
    }
}
