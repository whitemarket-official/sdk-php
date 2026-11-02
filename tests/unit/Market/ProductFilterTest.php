<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Market;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Market\ProductFilter;
use WhiteMarket\Sdk\Market\ProductSort;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\Color;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\Exterior;
use WhiteMarket\Sdk\Models\ItemCategory;
use WhiteMarket\Sdk\Models\ItemSubcategory;
use WhiteMarket\Sdk\Models\LogicOperand;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\OfferType;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\ProductSortField;
use WhiteMarket\Sdk\Models\Rarity;
use WhiteMarket\Sdk\Models\SortDirection;
use WhiteMarket\Sdk\Models\StickerFilm;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Support\TestDecimal;
use WhiteMarket\Sdk\Tests\Support\TestMoney;

#[CoversClass(ProductFilter::class)]
final class ProductFilterTest extends TestCase
{
    /**
     * Each constructor parameter alone -> the encoded `MarketProductSearchInput` it must produce.
     *
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function singleParameters(): \Iterator
    {
        yield 'empty filter -> nothing' => [[], []];
        yield 'app' => [['app' => App::Dota2], ['appId' => 'DOTA2']];
        yield 'nameHash' => [['nameHash' => 'AK-47 | Redline (Field-Tested)'], ['nameHash' => 'AK-47 | Redline (Field-Tested)']];
        yield 'nameHashes -> namesHash' => [['nameHashes' => ['a', 'b']], ['namesHash' => ['a', 'b']]];
        yield 'name' => [['name' => 'redline'], ['name' => 'redline']];
        yield 'names' => [['names' => ['redline', 'asiimov']], ['names' => ['redline', 'asiimov']]];
        yield 'nameStrict false is sent' => [['nameStrict' => false], ['nameStrict' => false]];
        yield 'ids' => [['ids' => ['id-1', 'id-2']], ['ids' => ['id-1', 'id-2']]];
        yield 'priceFrom only' => [['priceFrom' => TestMoney::usd('1.50')], ['price' => ['from' => ['currency' => 'USD', 'value' => '1.50']]]];
        yield 'priceTo only' => [['priceTo' => TestMoney::usd('99.990')], ['price' => ['to' => ['currency' => 'USD', 'value' => '99.990']]]];
        yield 'price range' => [
            ['priceFrom' => TestMoney::usd('0'), 'priceTo' => TestMoney::usd('10.00')],
            ['price' => ['from' => ['currency' => 'USD', 'value' => '0'], 'to' => ['currency' => 'USD', 'value' => '10.00']]],
        ];
        yield 'floatFrom keeps the decimal string' => [['floatFrom' => TestDecimal::of('0.0700')], ['csgoFloatFrom' => '0.0700']];
        yield 'floatTo keeps many decimals' => [['floatTo' => TestDecimal::of('0.07123456789012345678')], ['csgoFloatTo' => '0.07123456789012345678']];
        yield 'fadeFrom' => [['fadeFrom' => 90.5], ['csgoFadeFrom' => 90.5]];
        yield 'fadeTo' => [['fadeTo' => 100.0], ['csgoFadeTo' => 100.0]];
        yield 'fadeRankFrom' => [['fadeRankFrom' => 1], ['csgoFadeRankFrom' => 1]];
        yield 'fadeRankTo' => [['fadeRankTo' => 0], ['csgoFadeRankTo' => 0]];
        yield 'phase' => [['phase' => Phase::BlackPearl], ['csgoPhaseEnum' => 'BLACK_PEARL']];
        yield 'category' => [['category' => ItemCategory::cases()[0]], ['csgoItemCategoryEnum' => ItemCategory::cases()[0]->value]];
        yield 'subcategory' => [['subcategory' => ItemSubcategory::KnifeBowie], ['csgoItemSubcategoryEnum' => 'KNIFE_BOWIE']];
        yield 'excludeCategories' => [['excludeCategories' => ItemCategory::cases()], ['csgoItemCategoriesExclude' => array_map(static fn(ItemCategory $c): string => $c->value, ItemCategory::cases())]];
        yield 'excludeSubcategories' => [['excludeSubcategories' => [ItemSubcategory::KnifeBowie]], ['csgoItemSubcategoriesExclude' => ['KNIFE_BOWIE']]];
        yield 'rarities' => [['rarities' => [Rarity::cases()[0]]], ['csgoRarityEnum' => [Rarity::cases()[0]->value]]];
        yield 'exteriors' => [['exteriors' => [Exterior::cases()[0], Exterior::cases()[1]]], ['csgoExteriorEnum' => [Exterior::cases()[0]->value, Exterior::cases()[1]->value]]];
        yield 'colors' => [['colors' => [Color::cases()[0]]], ['colorEnum' => [Color::cases()[0]->value]]];
        yield 'empty list is sent' => [['rarities' => []], ['csgoRarityEnum' => []]];
        yield 'statTrak' => [['statTrak' => true], ['csgoStatTrak' => true]];
        yield 'souvenir' => [['souvenir' => false], ['csgoSouvenir' => false]];
        yield 'hasStickers -> csgoStickers' => [['hasStickers' => true], ['csgoStickers' => true]];
        yield 'hasCharm -> csgoCharm' => [['hasCharm' => true], ['csgoCharm' => true]];
        yield 'stickerNames' => [['stickerNames' => ['s1', 's2']], ['csgoStickerNames' => ['s1', 's2']]];
        yield 'stickerNamesBySlot with a gap keeps the slot positions' => [['stickerNamesBySlot' => ['a', null, 'b']], ['csgoStickerNamesBySlot' => ['a', null, 'b']]];
        yield 'stickerNamesBySlot' => [['stickerNamesBySlot' => ['s1', 's2']], ['csgoStickerNamesBySlot' => ['s1', 's2']]];
        yield 'stickerNamesOperand' => [['stickerNamesOperand' => LogicOperand::Or], ['csgoStickerNamesOperand' => 'OR']];
        yield 'stickerFilm' => [['stickerFilm' => StickerFilm::Holo], ['csgoStickerFilm' => 'HOLO']];
        yield 'stickerPlayerId' => [['stickerPlayerId' => 7], ['csgoStickerPlayer' => 7]];
        yield 'stickerTeamId' => [['stickerTeamId' => 8], ['csgoStickerTeam' => 8]];
        yield 'stickerTournamentId' => [['stickerTournamentId' => 9], ['csgoStickerTournament' => 9]];
        yield 'pattern zero is sent' => [['pattern' => 0], ['csgoPattern' => 0]];
        yield 'collection' => [['collection' => 'The Dust 2 Collection'], ['csgoCollection' => 'The Dust 2 Collection']];
        yield 'skin' => [['skin' => 'Redline'], ['csgoItemSkin' => 'Redline']];
        yield 'deliveryType' => [['deliveryType' => DeliveryType::Manual], ['deliveryType' => 'MANUAL']];
        yield 'deliveryPromise' => [['deliveryPromise' => DeliveryPromise::Hours12], ['deliveryPromise' => 'HOURS12']];
        yield 'offerType' => [['offerType' => OfferType::Bargain], ['offerType' => 'BARGAIN']];
        yield 'storeSlug' => [['storeSlug' => 'some-store'], ['storeSlug' => 'some-store']];
        yield 'sort defaults to ascending' => [['sort' => new ProductSort(ProductSortField::Price)], ['sort' => ['field' => 'PRICE', 'type' => 'ASC']]];
        yield 'sort descending' => [['sort' => new ProductSort(ProductSortField::Created, SortDirection::Desc)], ['sort' => ['field' => 'CREATED', 'type' => 'DESC']]];
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
        yield 'distinctValues' => [['distinctValues' => true], ['distinctValues' => true]];
        yield 'onlyPromo' => [['onlyPromo' => true], ['onlyPromo' => true]];
        yield 'popular -> isPopular' => [['popular' => true], ['isPopular' => true]];
        yield 'weaponAndGear -> isWeaponAndGear' => [['weaponAndGear' => true], ['isWeaponAndGear' => true]];
        yield 'special' => [['special' => true], ['special' => true]];
        yield 'superDeal' => [['superDeal' => true], ['superDeal' => true]];
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
        $filter = new ProductFilter(app: App::Csgo, priceTo: Money::usd('5'), floatFrom: Decimal::of('0.07'), statTrak: true);

        self::assertSame(
            ['appId' => 'CSGO', 'csgoFloatFrom' => '0.07', 'csgoStatTrak' => true, 'price' => ['to' => ['currency' => 'USD', 'value' => '5']]],
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
    private function filter(array $arguments): ProductFilter
    {
        return (new \ReflectionClass(ProductFilter::class))->newInstanceArgs($arguments);
    }
}
