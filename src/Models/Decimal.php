<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * An exact decimal number kept as the string the server sent (`"0.020"`, `"114.300"`), for money and wear values
 * (SDK_SPEC 4, CLAUDE.md rule 3). Never converted to a binary float: comparison works on the digits.
 *
 * {@see toString()} returns the value exactly as given, trailing zeros included; {@see equals()} and {@see compare()}
 * compare numerically, so `"1.50"` equals `"1.5"` and `"-0"` equals `"0"`.
 */
final readonly class Decimal implements \Stringable, \JsonSerializable
{
    private const PATTERN = '/^-?\d+(?:\.\d+)?$/D';

    private function __construct(
        private string $value,
    ) {}

    /**
     * @throws \InvalidArgumentException when the string is not a plain decimal (`-12.5`; no exponent, no `+`, no spaces)
     */
    public static function of(string $value): self
    {
        return self::tryOf($value) ?? throw new \InvalidArgumentException(\sprintf('"%s" is not a decimal number like "12.50".', $value));
    }

    /**
     * Like {@see of()}, but null instead of an exception whose message would hold the value (a server amount).
     */
    public static function tryOf(string $value): ?self
    {
        return preg_match(self::PATTERN, $value) === 1 ? new self($value) : null;
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function jsonSerialize(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->compare($other) === 0;
    }

    /**
     * @return -1|0|1
     */
    public function compare(self $other): int
    {
        [$sign, $integer, $fraction] = $this->parts();
        [$otherSign, $otherInteger, $otherFraction] = $other->parts();
        if ($sign !== $otherSign) {
            return $sign < $otherSign ? -1 : 1;
        }

        return $sign * $this->compareMagnitudes($integer, $fraction, $otherInteger, $otherFraction);
    }

    /**
     * Sign (-1, 0 or 1), the integer digits without leading zeros and the fraction digits without trailing zeros.
     *
     * @return array{-1|0|1, string, string}
     */
    private function parts(): array
    {
        $negative = str_starts_with($this->value, '-');
        $digits = ltrim($this->value, '-');
        $dot = strpos($digits, '.');
        $integer = ltrim($dot === false ? $digits : substr($digits, 0, $dot), '0');
        $fraction = $dot === false ? '' : rtrim(substr($digits, $dot + 1), '0');
        if ($integer === '' && $fraction === '') {
            return [0, '', ''];
        }

        return [$negative ? -1 : 1, $integer, $fraction];
    }

    /**
     * @return -1|0|1
     */
    private function compareMagnitudes(string $integer, string $fraction, string $otherInteger, string $otherFraction): int
    {
        $byLength = \strlen($integer) <=> \strlen($otherInteger);
        if ($byLength !== 0) {
            return $byLength;
        }
        $width = max(\strlen($fraction), \strlen($otherFraction));

        return strcmp(
            $integer . str_pad($fraction, $width, '0'),
            $otherInteger . str_pad($otherFraction, $width, '0'),
        ) <=> 0;
    }
}
