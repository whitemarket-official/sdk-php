<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * Known `extensions.category` values (SDK_SPEC 1.5), extended as the backend confirms them. A category missing here is
 * still delivered as a string in {@see GraphQLErrorEntry::$category}; constants, not an enum, so unknown values never throw.
 */
final class ErrorCategory
{
    public const MARKET_PRODUCT_NOT_FOUND = 'market.product.not_found';

    private const NOT_FOUND_SUFFIX = '.not_found';

    private function __construct() {}

    public static function isNotFound(?string $category): bool
    {
        return $category !== null && str_ends_with($category, self::NOT_FOUND_SUFFIX);
    }
}
