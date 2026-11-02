<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

#[CoversClass(Money::class)]
final class MoneyTest extends TestCase
{
    #[Test]
    public function buildsAUsdAmount(): void
    {
        $money = Money::usd('12.50');

        self::assertSame('12.50', $money->amount->toString());
        self::assertSame(Currency::Usd, $money->currency);
        self::assertSame('12.50 USD', (string) $money);
    }

    #[Test]
    public function rejectsAnAmountThatIsNotADecimal(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Money::usd('12.5e1');
    }

    #[Test]
    public function equalsTheSameAmountInTheSameCurrency(): void
    {
        self::assertTrue(Money::usd('1.50')->equals(Money::usd('1.5')));
        self::assertFalse(Money::usd('1.50')->equals(Money::usd('1.51')));
    }

    #[Test]
    public function doesNotEqualTheSameAmountInAnotherCurrency(): void
    {
        $euro = new Money(Decimal::of('1.50'), new UnknownEnumValue(Currency::class, 'EUR'));

        self::assertFalse(Money::usd('1.50')->equals($euro));
        self::assertSame('1.50 EUR', (string) $euro);
    }

    #[Test]
    public function equalsAnUnknownCurrencyByItsCode(): void
    {
        $a = new Money(Decimal::of('2'), new UnknownEnumValue(Currency::class, 'EUR'));
        $b = new Money(Decimal::of('2.0'), new UnknownEnumValue(Currency::class, 'EUR'));

        self::assertTrue($a->equals($b));
    }
}
