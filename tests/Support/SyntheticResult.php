<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

/**
 * Builds the smallest valid JSON value (as a PHP array) for a generated result class, so a test spells out only the
 * fields it is about: every nullable field is null, a required string is `"0"`, an int `0`, a bool `false`, a date
 * `2026-10-30T12:00:00+00:00`, an enum its first case, a list empty, a required object recursively the same.
 * Overrides replace whole fields; nested objects are built with another call.
 *
 * Synthetic data only (CLAUDE.md "Data policy"); the arrays go through the real generated `fromValue()`.
 */
final class SyntheticResult
{
    private const DATE_TIME = '2026-10-30T12:00:00+00:00';

    private function __construct() {}

    /**
     * @param class-string $class a generated result class
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function of(string $class, array $overrides = []): array
    {
        $constructor = (new \ReflectionClass($class))->getConstructor();
        if (!$constructor instanceof \ReflectionMethod) {
            throw new \LogicException($class . ' has no constructor');
        }
        $value = [];
        foreach ($constructor->getParameters() as $parameter) {
            $value[$parameter->getName()] = self::default($parameter->getType(), $class . '::$' . $parameter->getName());
        }

        return $overrides + $value;
    }

    /**
     * An object of one implementation of a GraphQL interface, with its `__typename`.
     *
     * @param class-string $class
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    public static function typed(string $typename, string $class, array $overrides = []): array
    {
        return ['__typename' => $typename] + self::of($class, $overrides);
    }

    private static function default(?\ReflectionType $type, string $where): mixed
    {
        if (!$type instanceof \ReflectionType || $type->allowsNull()) {
            return null;
        }
        $named = $type instanceof \ReflectionUnionType ? $type->getTypes()[0] ?? null : $type;
        if (!$named instanceof \ReflectionNamedType) {
            throw new \LogicException('Unsupported type of ' . $where);
        }

        return match ($named->getName()) {
            'string' => '0',
            'int' => 0,
            'bool' => false,
            'array' => [],
            \DateTimeImmutable::class => self::DATE_TIME,
            default => self::object($named->getName(), $where),
        };
    }

    private static function object(string $class, string $where): mixed
    {
        if (enum_exists($class)) {
            $cases = $class::cases();
            $first = $cases[0] ?? throw new \LogicException('Empty enum at ' . $where);

            return $first instanceof \BackedEnum ? $first->value : throw new \LogicException('Not a backed enum at ' . $where);
        }
        if (!class_exists($class)) {
            throw new \LogicException(\sprintf('A required %s at %s needs an explicit override', $class, $where));
        }

        return self::of($class);
    }
}
