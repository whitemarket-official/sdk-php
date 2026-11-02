<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * A sticker name that matches a search text (SDK_SPEC 3.2).
 */
final readonly class StickerSuggestion
{
    /**
     * @param string $name the value for `ProductFilter::$stickerNames`
     * @param ?string $icon icon URL, when the server has one
     * @param int $total how many listings carry this sticker
     */
    public function __construct(
        public string $name,
        public string $title,
        public ?string $icon,
        public StickerFilm|UnknownEnumValue|null $film,
        public int $total,
    ) {}
}
