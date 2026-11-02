<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Auth;

/**
 * A secret string (Partner Token, access token, Steam token, proxy credentials) that never prints its value
 * (SDK_SPEC 8). String, JSON and debug output show `***`; serialization is refused.
 *
 * The value lives in a local variable of a suspended generator, not in a property: `var_export()`, an `(array)` cast
 * and reflection-based dumpers read properties directly and ignore `__debugInfo()`, so a string property would leak,
 * and so would a closure (its `use` variables are dumped). A generator exposes no locals; the value is delivered by
 * `send()` rather than as an argument, because `ReflectionGenerator::getTrace()` lists arguments, and wrapped in
 * `SensitiveParameterValue`, because a backtrace taken while `send()` runs lists the argument of `send()`.
 *
 * Not covered (docs/DEVIATIONS.md, section 8): a backtrace taken by a signal handler or a timeout fatal at the very
 * moment an internal function such as the `SensitiveParameterValue` constructor holds the plain value as an argument.
 */
final readonly class Secret implements \JsonSerializable, \Stringable
{
    private const MASK = '***';

    /** @var \Generator<int, non-empty-string, \SensitiveParameterValue, never> */
    private \Generator $value;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('A secret must not be empty.');
        }
        $holder = $this->hold();
        // Wrapped: while send() runs the generator, a backtrace (a fatal error, a signal handler) shows its argument.
        $holder->send(new \SensitiveParameterValue($value));
        $this->value = $holder;
    }

    /**
     * The plain value, for the one place that sends it (an HTTP header). Never log or store the result.
     *
     * @return non-empty-string
     */
    public function reveal(): string
    {
        return $this->value->current();
    }

    /**
     * @return \Generator<int, non-empty-string, \SensitiveParameterValue, never>
     */
    private function hold(): \Generator
    {
        $wrapped = yield self::MASK;
        /** @var non-empty-string $value checked by the constructor */
        $value = $wrapped->getValue();
        while (true) {
            yield $value;
        }
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    /**
     * @return never
     */
    public function __serialize(): array
    {
        throw new \LogicException('A secret cannot be serialized.');
    }

    /**
     * @param array<mixed> $data
     */
    public function __unserialize(array $data): never
    {
        throw new \LogicException('A secret cannot be unserialized.');
    }
}
