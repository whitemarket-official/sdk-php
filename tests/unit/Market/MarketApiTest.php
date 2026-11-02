<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Market;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Enum\SteamApp;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\MarketProductSearchInput;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketItem\Result;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\EdgesNode;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\EdgesNodeItemCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\EdgesNodeItemCSGOInventoryItemProduct;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketList\PageInfo;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Market\MarketApi;
use WhiteMarket\Sdk\Market\ProductFilter;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\Product;
use WhiteMarket\Sdk\Models\StickerFilm;
use WhiteMarket\Sdk\Tests\Support\FakeOperationExecutor;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(MarketApi::class)]
#[CoversClass(PageRequest::class)]
final class MarketApiTest extends TestCase
{
    /**
     * @param array<string, mixed> ...$responses
     *
     * @return array{MarketApi, FakeOperationExecutor}
     */
    private function api(array ...$responses): array
    {
        $executor = new FakeOperationExecutor(...$responses);

        return [new MarketApi(new GeneratedClient($executor), new ModelMapper()), $executor];
    }

    /**
     * @param list<string> $slugs
     *
     * @return array<string, mixed> the `data` of a `market_list` response
     */
    private function listPage(array $slugs, ?string $endCursor, bool $hasNextPage): array
    {
        return ['market_list' => [
            'edges' => array_map(
                static fn(string $slug): array => ['cursor' => 'c-' . $slug, 'node' => SyntheticResult::of(EdgesNode::class, ['id' => 'id-' . $slug, 'slug' => $slug])],
                $slugs,
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
    public function searchSendsForwardPaginationOnlyWhenGiven(?PageRequest $page, array $expected): void
    {
        [$api, $executor] = $this->api($this->listPage(['ak-1'], null, false));

        $api->search(null, $page);

        $call = $executor->lastCall();
        self::assertSame(['MarketList', OperationType::Query], [$call['operationName'], $call['type']]);
        self::assertSame($expected, $call['variables']);
    }

    /**
     * At the depth limit the operation selects `product { item { __typename id } }` (SDK_SPEC 1.2, P22 B): the item
     * decodes by its type, and an implementation this SDK version does not know still decodes (rule 5).
     *
     * @return iterable<string, array{string}>
     */
    public static function depthLimitItemTypes(): iterable
    {
        yield 'known type' => ['CSGOInventoryItem'];
        yield 'unknown type' => ['FutureInventoryItem'];
    }

    #[Test]
    #[DataProvider('depthLimitItemTypes')]
    public function searchDecodesAnInterfaceFieldAtTheDepthLimitByItsTypename(string $typename): void
    {
        $inner = SyntheticResult::of(EdgesNodeItemCSGOInventoryItemProduct::class, ['item' => ['__typename' => $typename, 'id' => 'nested-item-1']]);
        $node = SyntheticResult::of(EdgesNode::class, [
            'id' => 'id-ak',
            'slug' => 'ak',
            'item' => SyntheticResult::typed('CSGOInventoryItem', EdgesNodeItemCSGOInventoryItem::class, ['product' => $inner]),
        ]);
        [$api] = $this->api(['market_list' => [
            'edges' => [['cursor' => 'c-ak', 'node' => $node]],
            'pageInfo' => SyntheticResult::of(PageInfo::class, ['endCursor' => null, 'hasNextPage' => false]),
            'totalCount' => 1,
        ]]);

        $page = $api->search();

        self::assertSame(['ak'], array_map(static fn(Product $product): string => $product->slug, $page->items));
        self::assertNotNull($page->items[0]->item ?? null, 'the inventory item itself still maps');
    }

    #[Test]
    public function searchMapsThePage(): void
    {
        [$api] = $this->api($this->listPage(['ak-1', 'awp-2'], 'cursor-2', true));

        $page = $api->search();

        self::assertSame(['ak-1', 'awp-2'], array_map(static fn(Product $product): string => $product->slug, $page->items));
        self::assertSame('cursor-2', $page->endCursor);
        self::assertTrue($page->hasNextPage);
        self::assertSame(5, $page->totalCount);
    }

    #[Test]
    public function searchEncodesAProductFilter(): void
    {
        [$api, $executor] = $this->api($this->listPage([], null, false));

        $api->search(new ProductFilter(app: App::Csgo, nameStrict: true));

        self::assertSame(['search' => ['appId' => 'CSGO', 'nameStrict' => true]], $executor->lastCall()['variables']);
    }

    #[Test]
    public function searchSendsARawInputExactly(): void
    {
        [$api, $executor] = $this->api($this->listPage([], null, false));

        $api->search(new MarketProductSearchInput(appId: SteamApp::TF2, csgoFloatFrom: '0.0700'));

        self::assertSame(['search' => ['appId' => 'TF2', 'csgoFloatFrom' => '0.0700']], $executor->lastCall()['variables']);
    }

    #[Test]
    public function iterateMakesNoRequestUntilItIsConsumed(): void
    {
        [$api, $executor] = $this->api($this->listPage(['a', 'b'], 'c2', true), $this->listPage(['c'], 'c3', false));

        $items = $api->iterate();
        self::assertCount(0, $executor->calls(), 'created, not started');

        self::assertSame('a', $items->current()->slug);
        self::assertCount(1, $executor->calls(), 'first item needs page 1 only');

        $items->next();
        self::assertSame('b', $items->current()->slug);
        self::assertCount(1, $executor->calls(), 'last item of page 1: page 2 is not requested yet');

        $items->next();
        self::assertSame('c', $items->current()->slug);
        self::assertCount(2, $executor->calls());
    }

    #[Test]
    public function iterateFollowsCursorsAndStopsWithoutANextPage(): void
    {
        [$api, $executor] = $this->api($this->listPage(['a', 'b'], 'c2', true), $this->listPage(['c'], 'c3', false));

        $keyed = [];
        foreach ($api->iterate(new ProductFilter(nameStrict: true), 2) as $key => $product) {
            $keyed[$key] = $product->slug;
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
    public function iterateWithoutAPageSizeSendsNoFirst(): void
    {
        [$api, $executor] = $this->api($this->listPage(['a'], 'c1', true), $this->listPage([], null, false));

        iterator_to_array($api->iterate(), false);

        self::assertSame([[], ['forwardPagination' => ['after' => 'c1']]], array_map(static fn(array $call): array => $call['variables'], $executor->calls()));
    }

    #[Test]
    public function iterateNamesTheListInTheErrorPathOfAMissingCursor(): void
    {
        [$api] = $this->api($this->listPage(['a'], null, true));

        try {
            iterator_to_array($api->iterate(), false);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('market_list.pageInfo.endCursor', $e->path);
        }
    }

    #[Test]
    public function getSendsTheIdOrSlugAndMapsTheProduct(): void
    {
        $node = SyntheticResult::of(Result::class, ['id' => 'id-1', 'slug' => 'ak-47-redline']);
        [$api, $executor] = $this->api(['market_item' => $node]);

        $product = $api->get('ak-47-redline');

        self::assertSame('ak-47-redline', $product->slug);
        self::assertSame('id-1', $product->id);
        self::assertSame(['idOrSlug' => 'ak-47-redline'], $executor->lastCall()['variables']);
        self::assertSame('MarketItem', $executor->lastCall()['operationName']);
    }

    /**
     * @return iterable<string, array{?int, ?int, int, int}> limit and offset passed (null: omitted), limit and offset sent
     */
    public static function suggestionWindows(): iterable
    {
        yield 'omitted -> schema defaults' => [null, null, 10, 0];
        yield 'explicit window' => [5, 20, 5, 20];
        yield 'zero offset kept' => [1, 0, 1, 0];
    }

    #[Test]
    #[DataProvider('suggestionWindows')]
    public function stickerSuggestionsSendsTheWindowAndMapsTheAnswer(?int $limitArgument, ?int $offsetArgument, int $limit, int $offset): void
    {
        [$api, $executor] = $this->api(['market_csgo_stickers_suggestion' => [
            'moreCount' => 7,
            'suggestions' => [['name' => 'cph2024_team_navi', 'title' => 'Natus Vincere', 'icon' => null, 'film' => 'HOLO', 'total' => 12]],
        ]]);

        $result = match (true) {
            $limitArgument === null || $offsetArgument === null => $api->stickerSuggestions('navi'),
            default => $api->stickerSuggestions('navi', $limitArgument, $offsetArgument),
        };

        self::assertSame(['limit' => $limit, 'offset' => $offset, 'search' => 'navi'], $executor->lastCall()['variables']);
        self::assertSame('MarketCsgoStickersSuggestion', $executor->lastCall()['operationName']);
        self::assertSame(7, $result->moreCount);
        $first = $result->items[0] ?? self::fail('no suggestion mapped');
        self::assertSame('cph2024_team_navi', $first->name);
        self::assertSame(StickerFilm::Holo, $first->film);
    }

    #[Test]
    public function checkTradeUrlSendsTheLinkAndReturnsTheAnswerUnchanged(): void
    {
        [$api, $executor] = $this->api(['market_trade_url' => ' Trade URL ok ']);

        $answer = $api->checkTradeUrl('https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic');

        self::assertSame(' Trade URL ok ', $answer);
        self::assertSame(['link' => ['value' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic']], $executor->lastCall()['variables']);
        self::assertSame('MarketTradeUrl', $executor->lastCall()['operationName']);
    }
}
