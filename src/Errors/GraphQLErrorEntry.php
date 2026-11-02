<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * One entry of the response's `errors` list. Branch on `category` (`extensions.category`, see {@see ErrorCategory}),
 * never on `message`: messages are for humans and change (CLAUDE.md rule 4).
 */
final readonly class GraphQLErrorEntry
{
    /**
     * @param list<string|int> $path response path of the failed field, e.g. `['market_item']`
     * @param list<array{line: int, column: int}> $locations positions in the sent document
     * @param array<mixed> $extensions the raw `extensions` object
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $message,
        #[\SensitiveParameter]
        public ?string $category = null,
        public array $path = [],
        public array $locations = [],
        #[\SensitiveParameter]
        public array $extensions = [],
    ) {}
}
