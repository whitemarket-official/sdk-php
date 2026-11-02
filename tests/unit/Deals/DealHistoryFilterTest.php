<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Deals;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Deals\DealHistoryFilter;
use WhiteMarket\Sdk\Deals\DealHistorySort;
use WhiteMarket\Sdk\Models\Color;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\DealHistorySortField;
use WhiteMarket\Sdk\Models\DealHistoryStatus;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Exterior;
use WhiteMarket\Sdk\Models\LogicOperand;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\Rarity;
use WhiteMarket\Sdk\Models\SortDirection;
use WhiteMarket\Sdk\Models\StickerFilm;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Support\TestDecimal;
use WhiteMarket\Sdk\Tests\Support\TestMoney;

#[CoversClass(DealHistoryFilter::class)]
#[CoversClass(DealHistorySort::class)]
final class DealHistoryFilterTest extends TestCase
{
    /**
     * Each constructor parameter alone -> the encoded `MarkerDealHistorySearchInput` it must produce.
     *
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function singleParameters(): \Iterator
    {
        yield 'empty filter -> nothing' => [[], []];
        yield 'action buy' => [['action' => DealAction::Buy], ['action' => 'BUY']];
        yield 'action sell' => [['action' => DealAction::Sell], ['action' => 'SELL']];
        foreach (DealHistoryStatus::cases() as $status) {
            yield 'status ' . $status->value => [['status' => $status], ['status' => $status->value]];
        }
        yield 'assetIds keep the 64-bit ids as strings' => [['assetIds' => ['40000000000000000002', '9007199254740993']], ['assetIds' => ['40000000000000000002', '9007199254740993']]];
        yield 'empty assetIds is sent' => [['assetIds' => []], ['assetIds' => []]];
        yield 'createdFrom only' => [
            ['createdFrom' => new \DateTimeImmutable('2026-10-01T00:00:00+00:00')],
            ['createdAt' => ['from' => '2026-10-01T00:00:00+00:00']],
        ];
        yield 'createdTo only' => [
            ['createdTo' => new \DateTimeImmutable('2026-10-31T12:30:00+02:00')],
            ['createdAt' => ['to' => '2026-10-31T12:30:00+02:00']],
        ];
        yield 'created range' => [
            ['createdFrom' => new \DateTimeImmutable('2026-10-01T00:00:00+00:00'), 'createdTo' => new \DateTimeImmutable('2026-10-02T00:00:00+00:00')],
            ['createdAt' => ['from' => '2026-10-01T00:00:00+00:00', 'to' => '2026-10-02T00:00:00+00:00']],
        ];
        yield 'priceFrom only' => [['priceFrom' => TestMoney::usd('1.50')], ['price' => ['from' => ['currency' => 'USD', 'value' => '1.50']]]];
        yield 'priceTo only' => [['priceTo' => TestMoney::usd('99.990')], ['price' => ['to' => ['currency' => 'USD', 'value' => '99.990']]]];
        yield 'price range' => [
            ['priceFrom' => TestMoney::usd('0'), 'priceTo' => TestMoney::usd('10.00')],
            ['price' => ['from' => ['currency' => 'USD', 'value' => '0'], 'to' => ['currency' => 'USD', 'value' => '10.00']]],
        ];
        yield 'nameHash' => [['nameHash' => 'AK-47 | Redline (Field-Tested)'], ['nameHash' => 'AK-47 | Redline (Field-Tested)']];
        yield 'name' => [['name' => 'redline'], ['name' => 'redline']];
        yield 'nameStrict false is sent' => [['nameStrict' => false], ['nameStrict' => false]];
        yield 'floatFrom keeps the decimal string' => [['floatFrom' => TestDecimal::of('0.0700')], ['csgoFloatFrom' => '0.0700']];
        yield 'floatTo keeps many decimals' => [['floatTo' => TestDecimal::of('0.07123456789012345678')], ['csgoFloatTo' => '0.07123456789012345678']];
        yield 'phase' => [['phase' => Phase::BlackPearl], ['csgoPhaseEnum' => 'BLACK_PEARL']];
        yield 'rarities' => [['rarities' => [Rarity::cases()[0]]], ['csgoRarityEnum' => [Rarity::cases()[0]->value]]];
        yield 'exteriors' => [['exteriors' => [Exterior::cases()[0], Exterior::cases()[1]]], ['csgoExteriorEnum' => [Exterior::cases()[0]->value, Exterior::cases()[1]->value]]];
        yield 'colors' => [['colors' => [Color::cases()[0]]], ['colorEnum' => [Color::cases()[0]->value]]];
        yield 'empty list is sent' => [['rarities' => []], ['csgoRarityEnum' => []]];
        yield 'statTrak' => [['statTrak' => true], ['csgoStatTrak' => true]];
        yield 'souvenir false is sent' => [['souvenir' => false], ['csgoSouvenir' => false]];
        yield 'hasStickers -> csgoStickers' => [['hasStickers' => true], ['csgoStickers' => true]];
        yield 'hasCharm -> csgoCharm' => [['hasCharm' => true], ['csgoCharm' => true]];
        yield 'stickerNames' => [['stickerNames' => ['s1', 's2']], ['csgoStickerNames' => ['s1', 's2']]];
        yield 'stickerNamesBySlot with a gap keeps the slot positions' => [['stickerNamesBySlot' => ['a', null, 'b']], ['csgoStickerNamesBySlot' => ['a', null, 'b']]];
        yield 'stickerNamesOperand' => [['stickerNamesOperand' => LogicOperand::Or], ['csgoStickerNamesOperand' => 'OR']];
        yield 'stickerFilm' => [['stickerFilm' => StickerFilm::Holo], ['csgoStickerFilm' => 'HOLO']];
        yield 'stickerPlayerId' => [['stickerPlayerId' => 7], ['csgoStickerPlayer' => 7]];
        yield 'stickerTeamId' => [['stickerTeamId' => 8], ['csgoStickerTeam' => 8]];
        yield 'stickerTournamentId' => [['stickerTournamentId' => 9], ['csgoStickerTournament' => 9]];
        yield 'pattern zero is sent' => [['pattern' => 0], ['csgoPattern' => 0]];
        yield 'collection' => [['collection' => 'The Dust 2 Collection'], ['csgoCollection' => 'The Dust 2 Collection']];
        yield 'onlyForOrders' => [['onlyForOrders' => true], ['onlyForOrders' => true]];
        yield 'sort defaults to ascending' => [['sort' => new DealHistorySort(DealHistorySortField::Price)], ['sort' => ['field' => 'PRICE', 'type' => 'ASC']]];
        yield 'sort descending' => [['sort' => new DealHistorySort(DealHistorySortField::CreatedAt, SortDirection::Desc)], ['sort' => ['field' => 'CREATED_AT', 'type' => 'DESC']]];
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('singleParameters')]
    public function encodesEachParameterToItsInputField(array $arguments, array $expected): void
    {
        self::assertSame($expected, $this->filter($arguments)->toInput()->toArray());
    }

    #[Test]
    public function combinesSeveralParameters(): void
    {
        $filter = new DealHistoryFilter(action: DealAction::Sell, status: DealHistoryStatus::Sold, priceTo: Money::usd('5'), floatFrom: Decimal::of('0.07'));

        self::assertSame(
            ['action' => 'SELL', 'csgoFloatFrom' => '0.07', 'price' => ['to' => ['currency' => 'USD', 'value' => '5']], 'status' => 'SOLD'],
            $filter->toInput()->toArray(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function priceParameters(): iterable
    {
        yield 'priceFrom' => ['priceFrom'];
        yield 'priceTo' => ['priceTo'];
    }

    #[Test]
    #[DataProvider('priceParameters')]
    public function rejectsAPriceInACurrencyThisSdkDoesNotKnow(string $parameter): void
    {
        $money = new Money(Decimal::of('1'), new UnknownEnumValue(Currency::class, 'EUR'));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('EUR');

        $this->filter([$parameter => $money]);
    }

    /**
     * @param array<string, mixed> $arguments named constructor arguments
     */
    private function filter(array $arguments): DealHistoryFilter
    {
        return (new \ReflectionClass(DealHistoryFilter::class))->newInstanceArgs($arguments);
    }

}
