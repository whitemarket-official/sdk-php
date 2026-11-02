<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Inventory;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Enum\CSGORarity;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\PersonInventorySearchInput;
use WhiteMarket\Sdk\Generated\Operation\Mutation\InventoryUpdate\Result as UpdateResult;
use WhiteMarket\Sdk\Generated\Operation\Query\InventoryMy\EdgesNodeCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\InventoryMy\PageInfo;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Inventory\InventoryApi;
use WhiteMarket\Sdk\Inventory\InventoryFilter;
use WhiteMarket\Sdk\Market\ProductSort;
use WhiteMarket\Sdk\Models\InventoryItem;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\ProductSortField;
use WhiteMarket\Sdk\Models\Rarity;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\FakeOperationExecutor;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Transport\RetryPolicy;

#[CoversClass(InventoryApi::class)]
final class InventoryApiTest extends TestCase
{
    /**
     * @param array<string, mixed> ...$responses
     *
     * @return array{InventoryApi, FakeOperationExecutor}
     */
    private function api(array ...$responses): array
    {
        $executor = new FakeOperationExecutor(...$responses);

        return [new InventoryApi(new GeneratedClient($executor), new ModelMapper()), $executor];
    }

    /**
     * @param list<string> $assetIds
     *
     * @return array<string, mixed> the `data` of an `inventory_my` response
     */
    private function listPage(array $assetIds, ?string $endCursor, bool $hasNextPage): array
    {
        return ['inventory_my' => [
            'edges' => array_map(
                static fn(string $assetId): array => ['cursor' => 'c-' . $assetId, 'node' => SyntheticResult::typed('CSGOInventoryItem', EdgesNodeCSGOInventoryItem::class, ['id' => 'id-' . $assetId, 'assetId' => $assetId])],
                $assetIds,
            ),
            'pageInfo' => SyntheticResult::of(PageInfo::class, ['endCursor' => $endCursor, 'hasNextPage' => $hasNextPage]),
            'totalCount' => 5,
        ]];
    }

    /**
     * @return iterable<string, array{?PageRequest, array<string, mixed>}> page request, expected variables
     */
    public static function paginationArguments(): iterable
    {
        yield 'no request -> no variable' => [null, []];
        yield 'empty request -> no variable' => [new PageRequest(), []];
        yield 'first and after' => [new PageRequest(50, 'cursor-1'), ['forwardPagination' => ['after' => 'cursor-1', 'first' => 50]]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('paginationArguments')]
    public function listSendsForwardPaginationOnlyWhenGiven(?PageRequest $page, array $expected): void
    {
        [$api, $executor] = $this->api($this->listPage(['1'], null, false));

        $api->list(null, $page);

        $call = $executor->lastCall();
        self::assertSame(['InventoryMy', OperationType::Query], [$call['operationName'], $call['type']]);
        self::assertSame($expected, $call['variables']);
    }

    #[Test]
    public function listMapsThePage(): void
    {
        [$api] = $this->api($this->listPage(['111', '222'], 'cursor-2', true));

        $page = $api->list();

        self::assertSame(['111', '222'], array_map(static fn(InventoryItem $item): string => $item->assetId, $page->items));
        self::assertSame(['id-111', 'id-222'], array_map(static fn(InventoryItem $item): string => $item->id, $page->items));
        self::assertSame('cursor-2', $page->endCursor);
        self::assertTrue($page->hasNextPage);
        self::assertSame(5, $page->totalCount);
    }

    #[Test]
    public function listEncodesAnInventoryFilter(): void
    {
        [$api, $executor] = $this->api($this->listPage([], null, false));

        $api->list(new InventoryFilter(rarities: [Rarity::cases()[0]], sort: new ProductSort(ProductSortField::Price)));

        self::assertSame(
            ['search' => ['csgoRarityEnum' => [Rarity::cases()[0]->value], 'sort' => ['field' => 'PRICE', 'type' => 'ASC']]],
            $executor->lastCall()['variables'],
        );
    }

    #[Test]
    public function listSendsARawInputExactly(): void
    {
        [$api, $executor] = $this->api($this->listPage([], null, false));

        $api->list(new PersonInventorySearchInput(csgoFloatFrom: '0.0700', csgoRarityEnum: [CSGORarity::cases()[0]]));

        self::assertSame(
            ['search' => ['csgoFloatFrom' => '0.0700', 'csgoRarityEnum' => [CSGORarity::cases()[0]->value]]],
            $executor->lastCall()['variables'],
        );
    }

    #[Test]
    public function iterateMakesNoRequestUntilItIsConsumed(): void
    {
        [$api, $executor] = $this->api($this->listPage(['a', 'b'], 'c2', true), $this->listPage(['c'], 'c3', false));

        $items = $api->iterate();
        self::assertCount(0, $executor->calls(), 'created, not started');

        self::assertSame('a', $items->current()->assetId);
        self::assertCount(1, $executor->calls(), 'first item needs page 1 only');

        $items->next();
        self::assertSame('b', $items->current()->assetId);
        self::assertCount(1, $executor->calls(), 'last item of page 1: page 2 is not requested yet');

        $items->next();
        self::assertSame('c', $items->current()->assetId);
        self::assertCount(2, $executor->calls());
    }

    #[Test]
    public function iterateFollowsCursorsKeepingTheFilterAndStopsWithoutANextPage(): void
    {
        [$api, $executor] = $this->api($this->listPage(['a', 'b'], 'c2', true), $this->listPage(['c'], 'c3', false));

        $keyed = [];
        foreach ($api->iterate(new InventoryFilter(nameStrict: true), 2) as $key => $item) {
            $keyed[$key] = $item->assetId;
        }

        self::assertSame([0 => 'a', 1 => 'b', 2 => 'c'], $keyed);
        self::assertSame(
            [
                ['forwardPagination' => ['first' => 2], 'search' => ['nameStrict' => true]],
                ['forwardPagination' => ['after' => 'c2', 'first' => 2], 'search' => ['nameStrict' => true]],
            ],
            array_map(static fn(array $call): array => $call['variables'], $executor->calls()),
        );
    }

    #[Test]
    public function iterateNamesTheListInTheErrorPathOfAMissingCursor(): void
    {
        [$api] = $this->api($this->listPage(['a'], null, true));

        try {
            iterator_to_array($api->iterate(), false);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('inventory_my.pageInfo.endCursor', $e->path);
        }
    }

    #[Test]
    public function refreshFromSteamSendsTheMutationOnce(): void
    {
        [$api, $executor] = $this->api(['inventory_update' => SyntheticResult::of(UpdateResult::class, ['inventoryUpdatedAt' => '2026-10-31T08:15:00+00:00'])]);

        $api->refreshFromSteam();

        self::assertCount(1, $executor->calls());
        self::assertSame(['InventoryUpdate', OperationType::Mutation, []], [$executor->lastCall()['operationName'], $executor->lastCall()['type'], $executor->lastCall()['variables']]);
    }

    /**
     * @return iterable<string, array{\Closure(): FakeHttpClient}>
     */
    public static function failures(): iterable
    {
        yield '503' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::status(503), FakeHttpClient::status(503))];
        yield 'network error' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::networkError(), FakeHttpClient::networkError())];
    }

    /**
     * @param \Closure(): FakeHttpClient $http
     */
    #[Test]
    #[DataProvider('failures')]
    public function refreshFromSteamIsSentOnceEvenWithTheDefaultRetryPolicy(\Closure $http): void
    {
        $fake = $http();
        $api = new InventoryApi(TestTransport::generatedClient($fake, new RetryPolicy()), new ModelMapper());

        try {
            $api->refreshFromSteam();
            self::fail('Expected a WhiteMarketException');
        } catch (WhiteMarketException) {
            self::assertCount(1, $fake->requests());
        }
    }
}
