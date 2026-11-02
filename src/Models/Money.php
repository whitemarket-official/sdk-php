<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * An exact amount of money (SDK_SPEC 4): a {@see Decimal}, never a binary float.
 */
final readonly class Money implements \Stringable
{
    public function __construct(
        public Decimal $amount,
        public Currency|UnknownEnumValue $currency,
    ) {}

    /**
     * @throws \InvalidArgumentException when the amount is not a plain decimal string
     */
    public static function usd(string $amount): self
    {
        return new self(Decimal::of($amount), Currency::Usd);
    }

    /**
     * Same currency and numerically equal amounts (`1.50 USD` equals `1.5 USD`).
     */
    public function equals(self $other): bool
    {
        return $this->currency->value === $other->currency->value && $this->amount->equals($other->amount);
    }

    /**
     * `"12.50 USD"`.
     */
    public function __toString(): string
    {
        return $this->amount->toString() . ' ' . $this->currency->value;
    }
}
