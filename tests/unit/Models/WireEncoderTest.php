<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\DateTimeRangeInput;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DealCancelReason;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Models\WireEncoder;

#[CoversClass(WireEncoder::class)]
final class WireEncoderTest extends TestCase
{
    #[Test]
    public function matchesAnEnumToTheWireEnumByValue(): void
    {
        $wire = new WireEncoder();

        self::assertSame(Wire\SteamApp::TF2, $wire->enum(Wire\SteamApp::class, App::Tf2));
        self::assertNull($wire->enum(Wire\SteamApp::class, null));
    }

    #[Test]
    public function matchesEveryEnumOfAListAndKeepsANullList(): void
    {
        $wire = new WireEncoder();

        self::assertSame([Wire\CSGOPhase::BLACK_PEARL, Wire\CSGOPhase::EMERALD], $wire->enumList(Wire\CSGOPhase::class, [Phase::BlackPearl, Phase::Emerald]));
        self::assertSame([], $wire->enumList(Wire\CSGOPhase::class, []));
        self::assertNull($wire->enumList(Wire\CSGOPhase::class, null));
    }

    #[Test]
    public function convertsMoneyKeepingTheExactAmountString(): void
    {
        $input = (new WireEncoder())->money(new Money(Decimal::of('12.50'), Currency::Usd));

        self::assertSame(['currency' => 'USD', 'value' => '12.50'], $input->toArray());
    }

    #[Test]
    public function refusesMoneyWithACurrencyItDoesNotKnow(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Unknown currency "EUR" cannot be sent.'));

        (new WireEncoder())->money(new Money(Decimal::of('1'), new UnknownEnumValue(Currency::class, 'EUR')));
    }

    #[Test]
    public function refusesAnEnumValueTheSchemaDoesNotAccept(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('DealCancelReason value NO_FUNDS cannot be sent: the API schema does not accept it.'));

        (new WireEncoder())->enum(Wire\MarketDealStatus::class, DealCancelReason::NoFunds);
    }

    #[Test]
    public function refusesAListWithAnEnumValueTheSchemaDoesNotAccept(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('DealCancelReason value NO_FUNDS cannot be sent: the API schema does not accept it.'));

        (new WireEncoder())->enumList(Wire\MarketDealStatus::class, [DealStatus::Offered, DealCancelReason::NoFunds]);
    }

    #[Test]
    public function convertsTheAcceptedValuesAroundARefusedOne(): void
    {
        self::assertSame([Wire\MarketDealStatus::OFFERED, Wire\MarketDealStatus::HOLD], (new WireEncoder())->enumList(Wire\MarketDealStatus::class, [DealStatus::Offered, DealStatus::Hold]));
    }

    #[Test]
    public function assertSendableAllChecksEveryPriceAndIgnoresNulls(): void
    {
        $wire = new WireEncoder();
        $wire->assertSendableAll();
        $wire->assertSendableAll(null, new Money(Decimal::of('1'), Currency::Usd), null);
        $unknown = new Money(Decimal::of('1'), new UnknownEnumValue(Currency::class, 'EUR'));

        $this->expectExceptionObject(new \InvalidArgumentException('Unknown currency "EUR" cannot be sent.'));

        $wire->assertSendableAll(null, new Money(Decimal::of('1'), Currency::Usd), $unknown);
    }

    /**
     * @return iterable<string, array{?string, ?string, ?array<string, mixed>}> the bounds as decimal strings
     */
    public static function moneyRanges(): iterable
    {
        yield 'no bounds -> no filter' => [null, null, null];
        yield 'from only' => ['1.10', null, ['from' => ['currency' => 'USD', 'value' => '1.10']]];
        yield 'to only' => [null, '2.200', ['to' => ['currency' => 'USD', 'value' => '2.200']]];
        yield 'both' => ['1.10', '2.200', ['from' => ['currency' => 'USD', 'value' => '1.10'], 'to' => ['currency' => 'USD', 'value' => '2.200']]];
    }

    /**
     * @param ?array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('moneyRanges')]
    public function buildsAMoneyRangeFromTheGivenBounds(?string $from, ?string $to, ?array $expected): void
    {
        $lower = $from === null ? null : new Money(Decimal::of($from), Currency::Usd);
        $upper = $to === null ? null : new Money(Decimal::of($to), Currency::Usd);

        self::assertSame($expected, (new WireEncoder())->moneyRange($lower, $upper)?->toArray());
    }

    #[Test]
    public function refusesAMoneyRangeBoundWithAnUnknownCurrency(): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('Unknown currency "EUR" cannot be sent.'));

        (new WireEncoder())->moneyRange(null, new Money(Decimal::of('1'), new UnknownEnumValue(Currency::class, 'EUR')));
    }

    /**
     * @return iterable<string, array{?\DateTimeImmutable, ?\DateTimeImmutable, bool}>
     */
    public static function dateRanges(): iterable
    {
        $a = new \DateTimeImmutable('2026-10-01T00:00:00+00:00');
        $b = new \DateTimeImmutable('2026-10-31T00:00:00+00:00');

        yield 'no bounds -> no filter' => [null, null, false];
        yield 'from only' => [$a, null, true];
        yield 'to only' => [null, $b, true];
        yield 'both' => [$a, $b, true];
    }

    #[Test]
    #[DataProvider('dateRanges')]
    public function buildsADateRangeOnlyWhenABoundIsGiven(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, bool $present): void
    {
        $range = (new WireEncoder())->dateRange($from, $to);

        self::assertSame($present, $range instanceof DateTimeRangeInput);
        self::assertSame($from, $range?->from);
        self::assertSame($to, $range?->to);
    }
}
