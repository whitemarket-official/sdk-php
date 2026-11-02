<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use WhiteMarket\Sdk\Errors\DecodeException;

/**
 * The paths and messages of the {@see DecodeException}s of the row sources: one place for `products[3]` and for the
 * texts that name a body that is not a list of valid JSON rows. No server bytes go into them.
 *
 * @internal
 */
final class RowPath
{
    /**
     * The path of the row at `$index`: `products[3]`.
     */
    public static function of(string $path, int $index): string
    {
        return \sprintf('%s[%d]', $path, $index);
    }

    /**
     * The row at `$index` is not valid JSON. The parser's exception is never chained: it quotes the body.
     */
    public static function invalidJson(string $path, int $index): DecodeException
    {
        return new DecodeException(self::of($path, $index), 'not valid JSON');
    }

    /**
     * The row at `$index` is longer than the limit of one row.
     */
    public static function elementTooLong(string $path, int $index): DecodeException
    {
        return new DecodeException(self::of($path, $index), 'a list element is too long');
    }

    /**
     * The body is not a JSON array of rows.
     */
    public static function notAList(string $path): DecodeException
    {
        return new DecodeException($path, 'not a list of rows');
    }
}
