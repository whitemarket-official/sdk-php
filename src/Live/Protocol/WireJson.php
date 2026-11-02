<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

use WhiteMarket\Sdk\Errors\DecodeException;

/**
 * The one JSON decode of the live module (the message and the `message` string inside it): depth 64, integers beyond
 * the platform int as digit strings, objects as arrays. Failures are {@see DecodeException}s without server bytes.
 *
 * @internal
 */
final class WireJson
{
    private const DEPTH = 64;

    private function __construct() {}

    /**
     * @throws DecodeException
     */
    public static function decode(#[\SensitiveParameter] string $json, string $path): mixed
    {
        try {
            return json_decode($json, true, self::DEPTH, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException $e) {
            // Not chained: its trace holds the json_decode() arguments, the message.
            $invalid = new DecodeException($path, $e->getCode() === \JSON_ERROR_DEPTH ? 'nested deeper than 64 levels' : 'not valid JSON');
        }

        throw $invalid;
    }

    /**
     * The decoded value when it is a JSON object (an empty one included), else null.
     *
     * @return array<string, mixed>|null
     */
    public static function objectOrNull(#[\SensitiveParameter] mixed $value): ?array
    {
        if (!\is_array($value) || array_is_list($value) && $value !== []) {
            return null;
        }
        /** @var array<string, mixed> $value */
        return $value;
    }
}
