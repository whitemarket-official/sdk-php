<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\GraphQL;

/**
 * Turns generated variables and input objects into JSON-ready arrays.
 *
 * @internal called by generated code only; not part of the public API
 */
final class Encode
{
    private function __construct() {}

    /**
     * Encodes every field and drops the `null` ones: an omitted field lets the server apply its default.
     *
     * @template K of array-key
     *
     * @param array<K, mixed> $fields
     *
     * @return array<K, mixed>
     */
    public static function object(#[\SensitiveParameter] array $fields): array
    {
        $encoded = [];
        foreach ($fields as $name => $value) {
            if ($value !== null) {
                $encoded[$name] = self::value($value);
            }
        }

        return $encoded;
    }

    /**
     * Public properties of a generated input object, encoded by {@see self::object()}. Reading them through
     * `get_object_vars()` keeps deprecated input fields usable without the generated code touching them by name.
     *
     * @return array<string, mixed>
     */
    public static function inputObject(#[\SensitiveParameter] InputObject $input): array
    {
        $fields = [];
        foreach (get_object_vars($input) as $name => $value) {
            $fields[(string) $name] = $value;
        }

        return self::object($fields);
    }

    /**
     * Input objects -> arrays, enums -> their wire value, date-times -> ISO-8601 with offset, lists element-wise.
     */
    public static function value(#[\SensitiveParameter] mixed $value): mixed
    {
        return match (true) {
            $value instanceof InputObject => $value->toArray(),
            $value instanceof \BackedEnum => $value->value,
            $value instanceof \DateTimeInterface => $value->format(\DATE_ATOM),
            \is_array($value) => self::list($value),
            default => $value,
        };
    }

    /**
     * A loop, not `array_map()`: an internal function's frame would put the whole array into the trace arguments of
     * an exception thrown while an element is encoded.
     *
     * @param array<mixed> $values
     *
     * @return array<mixed>
     */
    private static function list(#[\SensitiveParameter] array $values): array
    {
        $encoded = [];
        foreach ($values as $key => $value) {
            $encoded[$key] = self::value($value);
        }

        return $encoded;
    }
}
