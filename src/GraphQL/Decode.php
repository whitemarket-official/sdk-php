<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\GraphQL;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * Decoders used by the generated result classes and the live message decoder. Every decoder has the shape `callable(mixed $value, string $path): T`
 * so they compose: `Decode::listOf(Decode::nullableOf(Money::fromValue(...)))`.
 *
 * Strict about shape (a missing or mistyped field throws {@see DecodeException} with the JSON path), tolerant about
 * additions: unknown fields are ignored, unknown enum values and typenames are wrapped, never thrown (CLAUDE.md rule 5).
 *
 * Every response-data parameter is `#[\SensitiveParameter]`: a shape error in one field must not carry its siblings
 * (an access token next to a malformed flag) in the exception's trace arguments. Keep it on every new decoder.
 *
 * @internal called by generated code and the live message decoder only; not part of the public API
 */
final class Decode
{
    private const DATE_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,9})?(?:Z|[+-]\d{2}:?\d{2})$/D';

    private function __construct() {}

    /**
     * Decodes `$data[$key]`; a missing key is a shape error (GraphQL returns every selected field, `null` included).
     *
     * @template T
     *
     * @param array<mixed> $data
     * @param callable(mixed, string): T $decode
     *
     * @return T
     */
    public static function field(#[\SensitiveParameter] array $data, string $key, string $path, callable $decode): mixed
    {
        $fieldPath = $path === '' ? $key : $path . '.' . $key;
        if (!\array_key_exists($key, $data)) {
            throw new DecodeException($fieldPath, 'field is missing');
        }

        return $decode($data[$key], $fieldPath);
    }

    /**
     * @return array<mixed>
     */
    public static function object(#[\SensitiveParameter] mixed $value, string $path): array
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new DecodeException($path, 'expected an object, got ' . get_debug_type($value));
        }

        return $value;
    }

    public static function string(#[\SensitiveParameter] mixed $value, string $path): string
    {
        if (!\is_string($value)) {
            throw new DecodeException($path, 'expected a string, got ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * A credential field (an access token, a payment client secret) decodes to a Secret, never to a plain string.
     */
    public static function secret(#[\SensitiveParameter] mixed $value, string $path): Secret
    {
        $string = self::string($value, $path);
        if ($string === '') {
            throw new DecodeException($path, 'expected a non-empty secret');
        }

        return new Secret($string);
    }

    public static function int(#[\SensitiveParameter] mixed $value, string $path): int
    {
        if (!\is_int($value)) {
            throw new DecodeException($path, 'expected an integer, got ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * JSON has one number type: `1` and `1.0` are both valid GraphQL floats.
     */
    public static function float(#[\SensitiveParameter] mixed $value, string $path): float
    {
        if (!\is_float($value) && !\is_int($value)) {
            throw new DecodeException($path, 'expected a number, got ' . get_debug_type($value));
        }

        return (float) $value;
    }

    public static function bool(#[\SensitiveParameter] mixed $value, string $path): bool
    {
        if (!\is_bool($value)) {
            throw new DecodeException($path, 'expected a boolean, got ' . get_debug_type($value));
        }

        return $value;
    }

    /**
     * `DateTime` scalar: ISO-8601 with a time zone offset or `Z` (docs/WHITEMARKET_API.md "Scalars").
     */
    public static function dateTime(#[\SensitiveParameter] mixed $value, string $path): \DateTimeImmutable
    {
        $string = self::string($value, $path);
        if (preg_match(self::DATE_TIME_PATTERN, $string) !== 1) {
            // Only the length: a value outside the pattern can be anything the server put there, a credential included.
            throw new DecodeException($path, \sprintf('expected an ISO-8601 date-time with offset, got a string of %d bytes', \strlen($string)));
        }

        // The pattern admits only digits, separators and an offset, so the value is safe to name from here on.
        try {
            return new \DateTimeImmutable($string);
        } catch (\Exception $e) {
            throw new DecodeException($path, \sprintf('invalid date-time "%s"', $string), $e);
        }
    }

    /**
     * @template T
     *
     * @param callable(mixed, string): T $inner
     *
     * @return \Closure(mixed, string): (T|null)
     */
    public static function nullableOf(callable $inner): \Closure
    {
        return static fn(#[\SensitiveParameter] mixed $value, string $path): mixed => $value === null ? null : $inner($value, $path);
    }

    /**
     * @template T
     *
     * @param callable(mixed, string): T $item
     *
     * @return \Closure(mixed, string): list<T>
     */
    public static function listOf(callable $item): \Closure
    {
        return static function (#[\SensitiveParameter] mixed $value, string $path) use ($item): array {
            if (!\is_array($value) || !array_is_list($value)) {
                throw new DecodeException($path, 'expected a list, got ' . get_debug_type($value));
            }

            $items = [];
            foreach ($value as $index => $element) {
                $items[] = $item($element, \sprintf('%s[%d]', $path, $index));
            }

            return $items;
        };
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return \Closure(mixed, string): (T|UnknownEnumValue)
     */
    public static function enumOf(string $enum): \Closure
    {
        return static function (#[\SensitiveParameter] mixed $value, string $path) use ($enum): \BackedEnum|UnknownEnumValue {
            $string = self::string($value, $path);

            return $enum::tryFrom($string) ?? new UnknownEnumValue($enum, $string);
        };
    }

    /**
     * Interface without a single implementation in the schema: the object is kept raw.
     */
    public static function unknownType(#[\SensitiveParameter] mixed $value, string $path): UnknownTypeValue
    {
        $data = self::object($value, $path);

        return new UnknownTypeValue(self::field($data, '__typename', $path, self::string(...)), $data);
    }

    /**
     * Interface or union: picks the decoder by `__typename`.
     *
     * @template T
     *
     * @param array<string, callable(mixed, string): T> $variants keyed by `__typename`
     *
     * @return \Closure(mixed, string): (T|UnknownTypeValue)
     */
    public static function abstractOf(array $variants): \Closure
    {
        return static function (#[\SensitiveParameter] mixed $value, string $path) use ($variants): mixed {
            $data = self::object($value, $path);
            $typename = self::field($data, '__typename', $path, self::string(...));

            return isset($variants[$typename])
                ? $variants[$typename]($data, $path)
                : new UnknownTypeValue($typename, $data);
        };
    }
}
