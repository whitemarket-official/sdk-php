<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\EdgesNode;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\EdgesNodeProduct;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\PageInfo;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\Result;
use WhiteMarket\Sdk\Models\Deal;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\ProductHistory;
use WhiteMarket\Sdk\Tests\Support\SyntheticDeal;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(ModelMapper::class)]
#[CoversClass(ProductHistory::class)]
#[CoversClass(Deal::class)]
final class ModelMapperArchivedDealPageTest extends TestCase
{
    private const PATH = 'deal_history';

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
     * @return array{cursor: string, node: array<string, mixed>|null}
     */
    private function edge(?string $customId): array
    {
        return ['cursor' => 'c', 'node' => $customId === null ? null : SyntheticDeal::archived(EdgesNode::class, ['customId' => $customId], ['result' => EdgesNode::class, 'product' => EdgesNodeProduct::class, 'params' => EdgesNode::class . 'ProductParamsCSGOInventoryItem', 'description' => EdgesNode::class . 'ProductDescriptionCSGOSteamItem'])];
    }

    #[Test]
    public function mapsArchivedDealsCursorAndTotalCount(): void
    {
        $page = (new ModelMapper())->archivedDealPage($this->page([
            'edges' => [$this->edge('a'), $this->edge('b')],
            'pageInfo' => SyntheticResult::of(PageInfo::class, ['endCursor' => 'cursor-b', 'hasNextPage' => true]),
            'totalCount' => 42,
        ]), self::PATH);

        self::assertSame(['a', 'b'], array_map(static fn(Deal $d): ?string => $d->customId, $page->items));
        self::assertTrue(($page->items[0] ?? self::fail('no deal mapped'))->isArchived());
        self::assertSame('cursor-b', $page->endCursor);
        self::assertTrue($page->hasNextPage);
        self::assertSame(42, $page->totalCount);
    }

    #[Test]
    public function aNullEdgeListIsAnEmptyPage(): void
    {
        self::assertSame([], (new ModelMapper())->archivedDealPage($this->page(['edges' => null]), self::PATH)->items);
    }

    #[Test]
    public function skipsNullEdgesAndNullNodesKeepingTheOrder(): void
    {
        $page = (new ModelMapper())->archivedDealPage($this->page(['edges' => [$this->edge('a'), null, $this->edge(null), $this->edge('b')]]), self::PATH);

        self::assertSame(['a', 'b'], array_map(static fn(Deal $d): ?string => $d->customId, $page->items));
        self::assertSame([0, 1], array_keys($page->items), 'items stay a list');
    }

    #[Test]
    public function aMissingPageInfoMeansNoNextPage(): void
    {
        $page = (new ModelMapper())->archivedDealPage($this->page(['edges' => [$this->edge('a')], 'pageInfo' => null]), self::PATH);

        self::assertNull($page->endCursor);
        self::assertFalse($page->hasNextPage);
        self::assertCount(1, $page);
    }

    #[Test]
    public function reportsTheEdgeIndexInTheDecodePathOfABadDeal(): void
    {
        $bad = $this->edge('b');
        $bad['node']['price'] = ['currency' => 'USD', 'value' => 'not-a-number'];
        $result = $this->page(['edges' => [$this->edge('a'), $bad]]);

        try {
            (new ModelMapper())->archivedDealPage($result, self::PATH);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('deal_history.edges[1].node.price.value', $e->path);
        }
    }
}
