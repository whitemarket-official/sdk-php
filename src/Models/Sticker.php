<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * A sticker applied to an item.
 */
final readonly class Sticker
{
    /**
     * @param ?Decimal $wear scrape level as an exact decimal; null when the server does not report it
     */
    public function __construct(
        public string $name,
        public string $title,
        public ?Decimal $wear,
    ) {}
}
