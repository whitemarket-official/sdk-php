<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

use WhiteMarket\Sdk\Generated\Input\ForwardPaginationInput;

/**
 * One page request for every `list*` / `search` method (SDK_SPEC 3 "page"): how many items and where to continue.
 * Both parts are optional; the server's defaults apply to what is null.
 */
final readonly class PageRequest
{
    /**
     * @param ?int $first page size
     * @param ?string $after `Page::$endCursor` of the previous page
     */
    public function __construct(
        public ?int $first = null,
        public ?string $after = null,
    ) {}

    /**
     * @internal used by the high-level API; not part of the public API
     */
    public function toInput(): ?ForwardPaginationInput
    {
        if ($this->first === null && $this->after === null) {
            return null;
        }

        return new ForwardPaginationInput(after: $this->after, first: $this->first);
    }
}
