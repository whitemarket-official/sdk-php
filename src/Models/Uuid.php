<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * UUID helpers: generating a v4 UUID (customIds) and recognising the UUID shape (deal references).
 *
 * @internal not part of the public API
 */
final class Uuid
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD';

    private function __construct() {}

    /**
     * A random UUID version 4 in the `8-4-4-4-12` lower-case hex form.
     */
    public static function v4(): string
    {
        return self::v4FromBytes(random_bytes(16));
    }

    /**
     * Sets the version (4) and variant (RFC 4122) bits of 16 bytes and writes them as `8-4-4-4-12` hex.
     *
     * @param string $bytes exactly 16 bytes
     *
     * @throws \InvalidArgumentException `$bytes` is not exactly 16 bytes
     */
    public static function v4FromBytes(string $bytes): string
    {
        if (\strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('A UUID is made of exactly 16 bytes.');
        }
        $bytes[6] = \chr(\ord($bytes[6]) & 0x0F | 0x40);
        $bytes[8] = \chr(\ord($bytes[8]) & 0x3F | 0x80);
        $hex = bin2hex($bytes);

        return \sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }

    /**
     * Whether `$value` is written as `8-4-4-4-12` hex digits (any case); the version is not checked.
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
