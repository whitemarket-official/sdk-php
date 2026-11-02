<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * One page of a paginated list (SDK_SPEC 3). Pass {@see $endCursor} to the next `list*` call while
 * {@see $hasNextPage} is true, or use the matching `iterate*` method, which does that lazily.
 *
 * @template T
 *
 * @implements \IteratorAggregate<int, T>
 */
final readonly class Page implements \IteratorAggregate, \Countable
{
    /**
     * @param list<T> $items
     * @param ?int $totalCount the server's count of all matching items, when it reports one
     */
    public function __construct(
        public array $items,
        public ?string $endCursor,
        public bool $hasNextPage,
        public ?int $totalCount,
    ) {}

    /**
     * @return \ArrayIterator<int, T>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return \count($this->items);
    }
}
