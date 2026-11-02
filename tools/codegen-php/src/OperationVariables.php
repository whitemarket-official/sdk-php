<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\Codegen;

/**
 * PHP source fragments for an operation's variables, shared by the operation class and its client method.
 */
final readonly class OperationVariables
{
    /**
     * @param string $parameters parameter list: required first, then optional (`= null`)
     * @param list<string|null> $docs `@param` lines, `null` where the native type says everything
     * @param string $arguments named arguments that forward the parameters (`id: $id, search: $search`)
     * @param string $encoded array items for `Encode::object([...])`, one per line
     */
    public function __construct(
        public string $parameters,
        public array $docs,
        public string $arguments,
        public string $encoded,
    ) {}
}
