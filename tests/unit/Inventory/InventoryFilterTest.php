<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Inventory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Inventory\InventoryFilter;
use WhiteMarket\Sdk\Market\ProductSort;
use WhiteMarket\Sdk\Models\Color;
use WhiteMarket\Sdk\Models\Exterior;
use WhiteMarket\Sdk\Models\ItemCategory;
use WhiteMarket\Sdk\Models\ItemSubcategory;
use WhiteMarket\Sdk\Models\LogicOperand;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\ProductSortField;
use WhiteMarket\Sdk\Models\Rarity;
use WhiteMarket\Sdk\Models\SortDirection;
use WhiteMarket\Sdk\Tests\Support\TestDecimal;

#[CoversClass(InventoryFilter::class)]
#[CoversClass(ProductSort::class)]
final class InventoryFilterTest extends TestCase
{
    /**
     * Each constructor parameter alone -> the encoded `PersonInventorySearchInput` it must produce.
     *
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function singleParameters(): \Iterator
    {
        yield 'empty filter -> nothing' => [[], []];
        yield 'nameHash' => [['nameHash' => 'AK-47 | Redline (Field-Tested)'], ['nameHash' => 'AK-47 | Redline (Field-Tested)']];
        yield 'name' => [['name' => 'redline'], ['name' => 'redline']];
        yield 'nameStrict false is sent' => [['nameStrict' => false], ['nameStrict' => false]];
        yield 'category' => [['category' => ItemCategory::cases()[0]], ['csgoItemCategoryEnum' => ItemCategory::cases()[0]->value]];
        yield 'subcategory' => [['subcategory' => ItemSubcategory::KnifeBowie], ['csgoItemSubcategoryEnum' => 'KNIFE_BOWIE']];
        yield 'rarities' => [['rarities' => [Rarity::cases()[0]]], ['csgoRarityEnum' => [Rarity::cases()[0]->value]]];
        yield 'empty list is sent' => [['rarities' => []], ['csgoRarityEnum' => []]];
        yield 'exteriors' => [['exteriors' => [Exterior::cases()[0], Exterior::cases()[1]]], ['csgoExteriorEnum' => [Exterior::cases()[0]->value, Exterior::cases()[1]->value]]];
        yield 'colors' => [['colors' => [Color::cases()[0]]], ['colorEnum' => [Color::cases()[0]->value]]];
        yield 'phase' => [['phase' => Phase::BlackPearl], ['csgoPhaseEnum' => 'BLACK_PEARL']];
        yield 'floatFrom keeps the decimal string' => [['floatFrom' => TestDecimal::of('0.0700')], ['csgoFloatFrom' => '0.0700']];
        yield 'floatTo keeps many decimals' => [['floatTo' => TestDecimal::of('0.07123456789012345678')], ['csgoFloatTo' => '0.07123456789012345678']];
        yield 'fadeFrom' => [['fadeFrom' => 90.5], ['csgoFadeFrom' => 90.5]];
        yield 'fadeTo' => [['fadeTo' => 100.0], ['csgoFadeTo' => 100.0]];
        yield 'fadeRankFrom' => [['fadeRankFrom' => 1], ['csgoFadeRankFrom' => 1]];
        yield 'fadeRankTo zero is sent' => [['fadeRankTo' => 0], ['csgoFadeRankTo' => 0]];
        yield 'statTrak' => [['statTrak' => true], ['csgoStatTrak' => true]];
        yield 'souvenir false is sent' => [['souvenir' => false], ['csgoSouvenir' => false]];
        yield 'hasStickers -> csgoStickers' => [['hasStickers' => true], ['csgoStickers' => true]];
        yield 'hasCharm -> csgoCharm' => [['hasCharm' => true], ['csgoCharm' => true]];
        yield 'stickerNames' => [['stickerNames' => ['s1', 's2']], ['csgoStickerNames' => ['s1', 's2']]];
        yield 'stickerNamesOperand' => [['stickerNamesOperand' => LogicOperand::Or], ['csgoStickerNamesOperand' => 'OR']];
        yield 'pattern zero is sent' => [['pattern' => 0], ['csgoPattern' => 0]];
        yield 'collection' => [['collection' => 'The Dust 2 Collection'], ['csgoCollection' => 'The Dust 2 Collection']];
        yield 'sort defaults to ascending' => [['sort' => new ProductSort(ProductSortField::Price)], ['sort' => ['field' => 'PRICE', 'type' => 'ASC']]];
        yield 'sort descending' => [['sort' => new ProductSort(ProductSortField::Created, SortDirection::Desc)], ['sort' => ['field' => 'CREATED', 'type' => 'DESC']]];
    }

    /**
     * @param array<string, mixed> $arguments named constructor arguments
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('singleParameters')]
    public function encodesEachParameterToItsInputField(array $arguments, array $expected): void
    {
        $filter = (new \ReflectionClass(InventoryFilter::class))->newInstanceArgs($arguments);

        self::assertSame($expected, $filter->toInput()->toArray());
    }

    #[Test]
    public function combinesSeveralParameters(): void
    {
        $filter = new InventoryFilter(name: 'redline', floatFrom: TestDecimal::of('0.07'), statTrak: true);

        self::assertSame(['csgoFloatFrom' => '0.07', 'csgoStatTrak' => true, 'name' => 'redline'], $filter->toInput()->toArray());
    }
}
