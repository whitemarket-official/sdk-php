<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Operation\Query\InventoryMy\EdgesNodeCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\InventoryMy\PageInfo;
use WhiteMarket\Sdk\Generated\Operation\Query\InventoryMy\Result;
use WhiteMarket\Sdk\Models\InventoryItem;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Sticker;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(ModelMapper::class)]
#[CoversClass(Sticker::class)]
#[CoversClass(InventoryItem::class)]
final class ModelMapperInventoryPageTest extends TestCase
{
    private const PATH = 'inventory_my';

    /**
     * @param array<string, mixed> $overrides
     */
    private function page(array $overrides): Result
    {
        return Result::fromValue(
            $overrides + ['edges' => [], 'pageInfo' => SyntheticResult::of(PageInfo::class), 'totalCount' => null],
            self::PATH,
        );
    }

    /**
     * @param array<string, mixed> $item
     *
     * @return array{cursor: string, node: array<string, mixed>|null}
     */
    private function edge(?string $assetId, array $item = []): array
    {
        return ['cursor' => 'c', 'node' => $assetId === null ? null : SyntheticResult::typed('CSGOInventoryItem', EdgesNodeCSGOInventoryItem::class, ['assetId' => $assetId] + $item)];
    }

    /**
     * @param list<InventoryItem> $items
     *
     * @return list<string>
     */
    private function assetIds(array $items): array
    {
        return array_map(static fn(InventoryItem $i): string => $i->assetId, $items);
    }

    #[Test]
    public function mapsItemsCursorAndTotalCount(): void
    {
        $page = (new ModelMapper())->inventoryPage($this->page([
            'edges' => [$this->edge('a'), $this->edge('b')],
            'pageInfo' => SyntheticResult::of(PageInfo::class, ['endCursor' => 'cursor-b', 'hasNextPage' => true]),
            'totalCount' => 42,
        ]), self::PATH);

        self::assertSame(['a', 'b'], $this->assetIds($page->items));
        self::assertSame('cursor-b', $page->endCursor);
        self::assertTrue($page->hasNextPage);
        self::assertSame(42, $page->totalCount);
    }

    #[Test]
    public function aNullEdgeListIsAnEmptyPage(): void
    {
        self::assertSame([], (new ModelMapper())->inventoryPage($this->page(['edges' => null]), self::PATH)->items);
    }

    #[Test]
    public function skipsNullEdgesAndNullNodesKeepingTheOrder(): void
    {
        $page = (new ModelMapper())->inventoryPage($this->page(['edges' => [$this->edge('a'), null, $this->edge(null), $this->edge('b')]]), self::PATH);

        self::assertSame(['a', 'b'], $this->assetIds($page->items));
        self::assertSame([0, 1], array_keys($page->items), 'items stay a list');
    }

    #[Test]
    public function skipsAnItemOfATypeThisSdkDoesNotKnow(): void
    {
        $unknown = ['cursor' => 'c', 'node' => ['__typename' => 'Dota2InventoryItem']];

        $page = (new ModelMapper())->inventoryPage($this->page(['edges' => [$this->edge('a'), $unknown, $this->edge('b')]]), self::PATH);

        self::assertSame(['a', 'b'], $this->assetIds($page->items));
    }

    #[Test]
    public function aMissingPageInfoMeansNoNextPage(): void
    {
        $page = (new ModelMapper())->inventoryPage($this->page(['edges' => [$this->edge('a')], 'pageInfo' => null]), self::PATH);

        self::assertNull($page->endCursor);
        self::assertFalse($page->hasNextPage);
        self::assertCount(1, $page);
    }

    #[Test]
    public function mapsTheWearAsAnExactDecimalAndTheIdsAsStrings(): void
    {
        $item = $this->edge('76561198000000001', ['float' => '0.07123456789012345678', 'classId' => '9007199254740993']);

        $mapped = (new ModelMapper())->inventoryPage($this->page(['edges' => [$item]]), self::PATH)->items[0] ?? self::fail('no item mapped');

        self::assertSame('0.07123456789012345678', $mapped->float?->toString());
        self::assertSame(['76561198000000001', '9007199254740993'], [$mapped->assetId, $mapped->classId]);
    }

    #[Test]
    public function reportsTheEdgeIndexInTheDecodePathOfABadWear(): void
    {
        $result = $this->page(['edges' => [$this->edge('a'), $this->edge('b', ['float' => 'not-a-number'])]]);

        try {
            (new ModelMapper())->inventoryPage($result, self::PATH);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('inventory_my.edges[1].node.float', $e->path);
        }
    }
}
