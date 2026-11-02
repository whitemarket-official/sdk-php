<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * The answer of `market->stickerSuggestions()`: one window of matching stickers and how many more there are.
 *
 * @implements \IteratorAggregate<int, StickerSuggestion>
 */
final readonly class StickerSuggestions implements \IteratorAggregate, \Countable
{
    /**
     * @param list<StickerSuggestion> $items
     * @param int $moreCount matches beyond this window
     */
    public function __construct(
        public array $items,
        public int $moreCount,
    ) {}

    /**
     * @return \ArrayIterator<int, StickerSuggestion>
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
