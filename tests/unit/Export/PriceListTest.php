<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Export\ExportMapper;
use WhiteMarket\Sdk\Export\PriceList;
use WhiteMarket\Sdk\Export\PriceRow;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Tests\Support\TestExport;

#[CoversClass(PriceList::class)]
final class PriceListTest extends TestCase
{
    private const KARAMBIT = "\u{2605} Karambit | Doppler (Factory New)";

    #[Test]
    public function looksARowUpByNameAndPaintIndex(): void
    {
        $list = $this->list();

        self::assertSame('950.000', $list->get(self::KARAMBIT, '415')?->price->amount->toString());
        self::assertSame('1800.000', $list->get(self::KARAMBIT, '418')?->price->amount->toString());
    }

    #[Test]
    public function theDefaultPaintIndexIsZero(): void
    {
        self::assertSame('Sticker | Synthetic Crown (Foil)', $this->list()->get('Sticker | Synthetic Crown (Foil)')?->marketHashName);
    }

    #[Test]
    public function aNameAloneDoesNotFindAPhasedItem(): void
    {
        self::assertNull($this->list()->get(self::KARAMBIT));
    }

    #[Test]
    public function findsNothingForAnUnknownKey(): void
    {
        $list = $this->list();

        self::assertNull($list->get('No Such Item'));
        self::assertNull($list->get('AK-47 | Redline (Field-Tested)', '0'));
        self::assertSame([], $list->allPhases('No Such Item'));
    }

    #[Test]
    public function listsEveryPhaseOfAName(): void
    {
        $phases = $this->list()->allPhases(self::KARAMBIT);

        self::assertSame(['415', '418'], array_map(static fn(PriceRow $row): string => $row->paintIndex, $phases));
    }

    #[Test]
    public function countsAndIteratesInFileOrder(): void
    {
        $list = $this->list();

        self::assertCount(4, $list);
        self::assertSame(
            ['282', '0', '415', '418'],
            array_map(static fn(PriceRow $row): string => $row->paintIndex, iterator_to_array($list, false)),
        );
    }

    #[Test]
    public function aNumericNameIsStillFoundByItsString(): void
    {
        $row = new PriceRow('123', Money::usd('1.00'), 'https://white.market/x', 1, '1', null, '0', '0', null);

        $list = new PriceList([$row]);

        self::assertSame($row, $list->get('123'));
        self::assertSame([$row], $list->allPhases('123'));
    }

    #[Test]
    public function whenAKeyRepeatsTheFirstRowWinsAndBothArePhases(): void
    {
        $first = new PriceRow('X', Money::usd('1.00'), 'https://white.market/a', 1, '1', Decimal::of('0.1'), '5', '0', null);
        $second = new PriceRow('X', Money::usd('2.00'), 'https://white.market/b', 1, '2', null, '5', '0', null);

        $list = new PriceList([$first, $second]);

        self::assertSame($first, $list->get('X', '5'));
        self::assertSame([$first, $second], $list->allPhases('X'));
    }

    #[Test]
    public function anEmptyListHasNothing(): void
    {
        $list = new PriceList([]);

        self::assertCount(0, $list);
        self::assertNull($list->get('X'));
    }

    private function list(): PriceList
    {
        return new PriceList(ExportMapper::priceRows(TestExport::fixture('prices.json')));
    }
}
