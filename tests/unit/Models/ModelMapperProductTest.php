<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Fragment\CSGOStickerFields;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketNewMultiple\ProductMarketProduct;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketNewMultiple\ProductMarketProductItemCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketItem\ItemCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketItem\ItemCSGOInventoryItemDescriptionCSGOSteamItem;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketItem\Result;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\EdgesNode;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\EdgesNodeItemCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\EdgesNodeItemCSGOInventoryItemDescriptionCSGOSteamItem;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Product;
use WhiteMarket\Sdk\Models\ProductHistory;
use WhiteMarket\Sdk\Models\Sticker;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(ModelMapper::class)]
#[CoversClass(Sticker::class)]
#[CoversClass(ProductHistory::class)]
#[CoversClass(Product::class)]
final class ModelMapperProductTest extends TestCase
{
    private const PATH = 'market_item';

    /** Beyond 2^53: a float would round it. */
    private const ASSET_ID = '40000000000000000001';

    /** More digits than a double holds. */
    private const FLOAT = '0.07123456789012345678';

    /**
     * Each family of generated classes that carries a full `MarketProduct`; PHPStan checks each against the shape.
     *
     * @return iterable<string, array{\Closure(array<string, mixed>): Product}>
     */
    public static function fullSelections(): iterable
    {
        yield 'market_item' => [static fn(array $item): Product => (new ModelMapper())->product(
            Result::fromValue(self::product($item, Result::class, ItemCSGOInventoryItem::class, ItemCSGOInventoryItemDescriptionCSGOSteamItem::class), self::PATH),
            self::PATH,
        )];
        yield 'market_list node' => [static fn(array $item): Product => (new ModelMapper())->product(
            EdgesNode::fromValue(self::product($item, EdgesNode::class, EdgesNodeItemCSGOInventoryItem::class, EdgesNodeItemCSGOInventoryItemDescriptionCSGOSteamItem::class), self::PATH),
            self::PATH,
        )];
    }

    /**
     * @param \Closure(array<string, mixed>): Product $map
     */
    #[Test]
    #[DataProvider('fullSelections')]
    public function mapsAProductWithItsCs2Item(\Closure $map): void
    {
        $product = $map([]);

        self::assertSame('a9a1e5b0-6c1e-4f43-8d7e-2f0b9c3d4e51', $product->id);
        self::assertSame('ak-47-redline-ft-1', $product->slug);
        self::assertSame('12.340 USD', (string) $product->price);
        self::assertSame('2026-10-29T08:30:00+00:00', $product->createdAt->format(\DATE_ATOM));
        self::assertTrue($product->isOwner);
        self::assertSame(DeliveryType::Manual, $product->deliveryType);
        self::assertSame(DeliveryPromise::Hours12, $product->deliveryPromise);

        $item = $product->item;
        self::assertNotNull($item);
        self::assertSame('5d0c7c1a-1b2a-4a8e-9f1d-0e7a4b6c2d10', $item->id);
        self::assertSame(self::ASSET_ID, $item->assetId);
        self::assertSame('310776543', $item->classId);
        self::assertSame('302028390', $item->instanceId);
        self::assertSame('AK-47 | Redline (Field-Tested)', $item->nameHash);
        self::assertSame('AK-47 | Redline', $item->name);
        self::assertSame(self::FLOAT, $item->float?->toString());
        self::assertSame('321', $item->paintSeed);
        self::assertSame('282', $item->paintIndex);
        self::assertNull($item->phase);
        self::assertSame('steam://rungame/730/synthetic-inspect-link', $item->inspectLink);
        self::assertTrue($item->isStatTrak);
        self::assertFalse($item->isSouvenir);
    }

    #[Test]
    public function keepsStickersInOrderAndSkipsNullEntries(): void
    {
        $product = $this->marketItem(['stickers' => [
            self::sticker('Crown (Foil)', '0.0300'),
            null,
            self::sticker('Titan | Katowice 2014', null),
        ]]);

        $stickers = $product->item->stickers ?? [];
        self::assertCount(2, $stickers);
        self::assertSame('Crown (Foil)', $stickers[0]->name);
        self::assertSame('Crown (Foil) title', $stickers[0]->title);
        self::assertSame('0.0300', $stickers[0]->wear?->toString());
        self::assertSame('Titan | Katowice 2014', $stickers[1]->name);
        self::assertNull($stickers[1]->wear);
    }

    #[Test]
    public function aShallowItemSelectionHasNoDescriptionAndNoStickers(): void
    {
        $data = self::product([], ProductMarketProduct::class, ProductMarketProductItemCSGOInventoryItem::class, null);
        $product = (new ModelMapper())->product(ProductMarketProduct::fromValue($data, self::PATH), self::PATH);

        self::assertSame(self::ASSET_ID, $product->item?->assetId);
        self::assertNull($product->item->name);
        self::assertNull($product->item->isStatTrak);
        self::assertNull($product->item->isSouvenir);
        self::assertSame([], $product->item->stickers);
    }

    #[Test]
    public function anItemTypeThisSdkDoesNotKnowMapsToNull(): void
    {
        $data = self::product([], Result::class, ItemCSGOInventoryItem::class, null);
        $data['item'] = ['__typename' => 'Dota2InventoryItem', 'id' => 'x'];

        $product = (new ModelMapper())->product(Result::fromValue($data, self::PATH), self::PATH);

        self::assertNull($product->item);
    }

    #[Test]
    public function aProductWithoutAnItemMapsTheItemToNull(): void
    {
        $data = self::product([], Result::class, ItemCSGOInventoryItem::class, null);
        $data['item'] = null;

        self::assertNull((new ModelMapper())->product(Result::fromValue($data, self::PATH), self::PATH)->item);
    }

    #[Test]
    public function aDescriptionTypeThisSdkDoesNotKnowLeavesTheDescribedFieldsNull(): void
    {
        $product = $this->marketItem(['description' => ['__typename' => 'Dota2SteamItem']]);

        self::assertSame(self::ASSET_ID, $product->item?->assetId);
        self::assertNull($product->item->name);
        self::assertNull($product->item->isStatTrak);
    }

    #[Test]
    public function keepsUnknownDeliveryValuesAsTheirRawValue(): void
    {
        $data = self::product([], Result::class, ItemCSGOInventoryItem::class, null);
        $data['deliveryType'] = 'DRONE';
        $data['deliveryPromise'] = 'MINUTES1';

        $product = (new ModelMapper())->product(Result::fromValue($data, self::PATH), self::PATH);

        self::assertInstanceOf(UnknownEnumValue::class, $product->deliveryType);
        self::assertSame(DeliveryType::class, $product->deliveryType->enum);
        self::assertSame('DRONE', $product->deliveryType->value);
        self::assertInstanceOf(UnknownEnumValue::class, $product->deliveryPromise);
        self::assertSame('MINUTES1', $product->deliveryPromise->value);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedDecimals(): iterable
    {
        yield 'item float' => [['float' => '7e-2'], self::PATH . '.item.float'];
        yield 'sticker wear' => [['stickers' => [self::sticker('a', '0.1'), self::sticker('b', 'worn')]], self::PATH . '.item.stickers[1].wear'];
    }

    /**
     * @param array<string, mixed> $item
     */
    #[Test]
    #[DataProvider('malformedDecimals')]
    public function rejectsAWearValueThatIsNotADecimalWithItsPath(array $item, string $path): void
    {
        try {
            $this->marketItem($item);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame($path, $e->path);
        }
    }

    #[Test]
    public function rejectsAPriceThatIsNotADecimalWithItsPath(): void
    {
        $data = self::product([], Result::class, ItemCSGOInventoryItem::class, null);
        $data['price'] = ['currency' => 'USD', 'value' => '12,34'];

        $this->expectExceptionObject(new DecodeException(self::PATH . '.price.value', 'not a decimal number'));

        (new ModelMapper())->product(Result::fromValue($data, self::PATH), self::PATH);
    }

    /**
     * @param array<string, mixed> $item
     */
    private function marketItem(array $item): Product
    {
        $data = self::product($item, Result::class, ItemCSGOInventoryItem::class, ItemCSGOInventoryItemDescriptionCSGOSteamItem::class);

        return (new ModelMapper())->product(Result::fromValue($data, self::PATH), self::PATH);
    }

    /**
     * @param array<string, mixed> $item overrides of the item
     * @param class-string $productClass
     * @param class-string $itemClass
     * @param ?class-string $descriptionClass null for a selection without a description
     *
     * @return array<string, mixed>
     */
    private static function product(array $item, string $productClass, string $itemClass, ?string $descriptionClass): array
    {
        $itemFields = [
            'id' => '5d0c7c1a-1b2a-4a8e-9f1d-0e7a4b6c2d10',
            'assetId' => self::ASSET_ID,
            'classId' => '310776543',
            'instanceId' => '302028390',
            'nameHash' => 'AK-47 | Redline (Field-Tested)',
            'float' => self::FLOAT,
            'paintSeed' => '321',
            'paintIndex' => '282',
            'phase' => null,
            'link' => 'steam://rungame/730/synthetic-inspect-link',
        ];
        if ($descriptionClass !== null) {
            $itemFields['description'] = SyntheticResult::typed('CSGOSteamItem', $descriptionClass, [
                'name' => 'AK-47 | Redline',
                'isStatTrak' => true,
                'isSouvenir' => false,
            ]);
        }

        return [
            'id' => 'a9a1e5b0-6c1e-4f43-8d7e-2f0b9c3d4e51',
            'slug' => 'ak-47-redline-ft-1',
            'price' => ['currency' => 'USD', 'value' => '12.340'],
            'createdAt' => '2026-10-29T08:30:00+00:00',
            'isOwner' => true,
            'deliveryType' => 'MANUAL',
            'deliveryPromise' => 'HOURS12',
            'item' => SyntheticResult::typed('CSGOInventoryItem', $itemClass, $item + $itemFields),
        ] + SyntheticResult::of($productClass);
    }

    /**
     * @return array<string, mixed>
     */
    private static function sticker(string $name, ?string $wear): array
    {
        return SyntheticResult::of(CSGOStickerFields::class, ['name' => $name, 'title' => $name . ' title', 'wear' => $wear]);
    }
}
