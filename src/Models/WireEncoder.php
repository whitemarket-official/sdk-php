<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\DateTimeRangeInput;
use WhiteMarket\Sdk\Generated\Input\MoneyInput;
use WhiteMarket\Sdk\Generated\Input\MoneyRangeInput;

/**
 * Converts high-level values (enums, {@see Money}) into the generated input types (CLAUDE.md rule 12: API names only at
 * the boundary). High-level enums are matched to the wire enum by value.
 *
 * @internal shared by the high-level API classes; not part of the public API
 */
final class WireEncoder
{
    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $wire
     *
     * @return ($value is null ? null : E)
     *
     * @throws \InvalidArgumentException the schema has no such value (for example `DealStatus::Queued`)
     */
    public function enum(string $wire, ?\BackedEnum $value): ?\BackedEnum
    {
        return $value instanceof \BackedEnum ? $this->convert($wire, $value) : null;
    }

    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $wire
     * @param list<\BackedEnum>|null $values
     *
     * @return list<E>|null
     *
     * @throws \InvalidArgumentException the schema has no such value (for example `DealStatus::Queued`)
     */
    public function enumList(string $wire, ?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        return array_map(fn(\BackedEnum $value): \BackedEnum => $this->convert($wire, $value), $values);
    }

    /**
     * @throws \InvalidArgumentException the currency of `$money` is not one this SDK knows, so it cannot be sent
     */
    public function assertSendable(Money $money): void
    {
        if ($money->currency instanceof UnknownEnumValue) {
            throw new \InvalidArgumentException(\sprintf('Unknown currency "%s" cannot be sent.', $money->currency->value));
        }
    }

    /**
     * @throws \InvalidArgumentException the currency of `$money` is not one this SDK knows, so it cannot be sent
     */
    public function money(Money $money): MoneyInput
    {
        $this->assertSendable($money);

        return new MoneyInput(Wire\Currency::from($money->currency->value), $money->amount->toString());
    }

    /**
     * @throws \InvalidArgumentException a currency is not one this SDK knows, so it cannot be sent
     */
    public function assertSendableAll(?Money ...$prices): void
    {
        foreach ($prices as $price) {
            if ($price instanceof Money) {
                $this->assertSendable($price);
            }
        }
    }

    /**
     * @return ?MoneyRangeInput null when both bounds are null
     *
     * @throws \InvalidArgumentException a currency is not one this SDK knows, so it cannot be sent
     */
    public function moneyRange(?Money $from, ?Money $to): ?MoneyRangeInput
    {
        if (!$from instanceof Money && !$to instanceof Money) {
            return null;
        }

        return new MoneyRangeInput($from instanceof Money ? $this->money($from) : null, $to instanceof Money ? $this->money($to) : null);
    }

    /**
     * @return ?DateTimeRangeInput null when both bounds are null
     */
    public function dateRange(?\DateTimeInterface $from, ?\DateTimeInterface $to): ?DateTimeRangeInput
    {
        return $from instanceof \DateTimeInterface || $to instanceof \DateTimeInterface ? new DateTimeRangeInput($from, $to) : null;
    }

    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $wire
     *
     * @return E
     *
     * @throws \InvalidArgumentException
     */
    private function convert(string $wire, \BackedEnum $value): \BackedEnum
    {
        return $wire::tryFrom($value->value) ?? throw new \InvalidArgumentException(\sprintf('%s value %s cannot be sent: the API schema does not accept it.', (new \ReflectionClass($value))->getShortName(), $value->value));
    }
}
