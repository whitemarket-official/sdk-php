<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\GraphQL;

/**
 * An interface or union member whose `__typename` this SDK version does not know yet (CLAUDE.md rule 5).
 * Keeps the raw decoded object.
 */
final readonly class UnknownTypeValue
{
    /**
     * @param array<mixed> $raw
     */
    public function __construct(
        public string $typename,
        public array $raw,
    ) {}
}
