<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;

/**
 * Turns a "fetch one page after this cursor" function into a lazy stream of items: the next page is requested only
 * after every item of the previous one was consumed (SDK_SPEC 3).
 *
 * @internal used by the high-level API; not part of the public API
 */
final class Paginator
{
    /**
     * @template T
     *
     * @param-later-invoked-callable $fetchPage
     * @param \Closure(?string): Page<T> $fetchPage argument: the `after` cursor, null for the first page
     * @param string $path JSON path of the page in the response, for the {@see DecodeException} about a missing cursor
     *
     * @return \Generator<int, T>
     *
     * @throws WhiteMarketException the page fetch failed, or (as {@see DecodeException}) the server reports another page but no cursor to reach it (or the cursor just used)
     */
    public function iterate(\Closure $fetchPage, string $path): \Generator
    {
        $key = 0;
        $cursor = null;
        do {
            $page = $fetchPage($cursor);
            foreach ($page->items as $item) {
                yield $key++ => $item;
            }
            if (!$page->hasNextPage) {
                return;
            }
            if ($page->endCursor === null || $page->endCursor === $cursor) {
                throw new DecodeException($path . '.pageInfo.endCursor', 'hasNextPage is true but there is no new cursor to continue from');
            }
            $cursor = $page->endCursor;
        } while (true);
    }
}
