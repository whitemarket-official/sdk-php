<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Selling;

use WhiteMarket\Sdk\Models\Product;
use WhiteMarket\Sdk\Models\ProductHistory;

/**
 * The result for one item of a `*Many` call of {@see SellingApi}.
 */
final readonly class BatchItemResult
{
    /**
     * @param int $index position of the item in the caller's list, 0-based
     * @param Product|ProductHistory|null $product a live listing, or the archived one for a removal; null when the server sent none
     * @param ?string $errorCategory `extensions.category`-style category the server reported for a failed item
     * @param ?string $errorMessage the server's message for a failed item (display only; never branch on it)
     */
    public function __construct(
        public int $index,
        public BatchOutcome $outcome,
        public Product|ProductHistory|null $product = null,
        public ?string $errorCategory = null,
        public ?string $errorMessage = null,
    ) {}
}
