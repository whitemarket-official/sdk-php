<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Selling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\BadRequestException;
use WhiteMarket\Sdk\Errors\BatchIncompleteException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Enum\SteamApp;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\MarketProductSearchInput;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketEditMultiple;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketNew;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketNewMultiple;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketRemove\DescriptionCSGOSteamItem;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketRemove\ParamsCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketRemove\Result;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketRemoveMultiple;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketMy\EdgesNode;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketMy\PageInfo;
use WhiteMarket\Sdk\Market\ProductFilter;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\Product;
use WhiteMarket\Sdk\Models\ProductHistory;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Selling\BatchItemResult;
use WhiteMarket\Sdk\Selling\BatchOutcome;
use WhiteMarket\Sdk\Selling\Listing;
use WhiteMarket\Sdk\Selling\ListingOptions;
use WhiteMarket\Sdk\Selling\PriceChange;
use WhiteMarket\Sdk\Selling\SellingApi;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\FakeOperationExecutor;
use WhiteMarket\Sdk\Tests\Support\SyntheticDeal;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;
use WhiteMarket\Sdk\Tests\Support\TestMoney;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Transport\RetryPolicy;

#[CoversClass(SellingApi::class)]
#[CoversClass(BatchItemResult::class)]
#[CoversClass(ModelMapper::class)]
final class SellingApiTest extends TestCase
{
    private const PRODUCT_ID = '7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13';

    private const INVENTORY_ID = '0f6d2b8e-5a7c-4e1f-9b3d-6c2a8e4f1d70';

    private const ASSET_ID = '40000000000000000002';

    private const PRICE = ['currency' => 'USD', 'value' => '12.50'];

    /** operation name, root field, generated namespace, of every `*Many` method */
    private const MANY = [
        'listMany' => ['MarketNewMultiple', 'market_new_multiple', MarketNewMultiple::class],
        'repriceMany' => ['MarketEditMultiple', 'market_edit_multiple', MarketEditMultiple::class],
        'removeMany' => ['MarketRemoveMultiple', 'market_remove_multiple', MarketRemoveMultiple::class],
    ];

    /**
     * @param int<1, max> $batchSize
     *
     * @return array{SellingApi, FakeHttpClient}
     */
    private function api(FakeHttpClient $http, int $batchSize = SellingApi::DEFAULT_BATCH_SIZE): array
    {
        // The default retry policy: a mutation must still be sent once.
        $generated = TestTransport::generatedClient($http, new RetryPolicy());

        return [new SellingApi($generated, new ModelMapper(), $batchSize), $http];
    }

    // ---- construction ------------------------------------------------------------------------------------------

    #[Test]
    public function theDefaultBatchSizeIs100(): void
    {
        self::assertSame(100, (new \ReflectionClassConstant(SellingApi::class, 'DEFAULT_BATCH_SIZE'))->getValue());
    }

    #[Test]
    public function aBatchSizeBelowOneIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The batch size must be at least 1.');

        (new \ReflectionClass(SellingApi::class))->newInstanceArgs([new GeneratedClient(new FakeOperationExecutor()), new ModelMapper(), 0]);
    }

    // ---- list --------------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, ?array{DeliveryType, ?DeliveryPromise, ?int}, array<string, mixed>}> item, options (the arguments of {@see ListingOptions}), expected variables
     */
    public static function listings(): iterable
    {
        yield 'inventory id, no options' => [self::INVENTORY_ID, null, ['inventoryId' => self::INVENTORY_ID, 'price' => self::PRICE, 'deliveryType' => 'SEMI']];
        yield 'asset id, no options' => [self::ASSET_ID, null, ['assetId' => self::ASSET_ID, 'price' => self::PRICE, 'deliveryType' => 'SEMI']];
        yield 'upper-case inventory id is sent as given' => [strtoupper(self::INVENTORY_ID), null, ['inventoryId' => strtoupper(self::INVENTORY_ID), 'price' => self::PRICE, 'deliveryType' => 'SEMI']];
        yield 'default options object' => [self::ASSET_ID, [DeliveryType::Semi, null, null], ['assetId' => self::ASSET_ID, 'price' => self::PRICE, 'deliveryType' => 'SEMI']];
        yield 'manual delivery' => [self::ASSET_ID, [DeliveryType::Manual, null, null], ['assetId' => self::ASSET_ID, 'price' => self::PRICE, 'deliveryType' => 'MANUAL']];
        yield 'lifetime only' => [self::ASSET_ID, [DeliveryType::Semi, null, 60], ['assetId' => self::ASSET_ID, 'price' => self::PRICE, 'deliveryType' => 'SEMI', 'lifetime' => 60]];
        yield 'promise only' => [self::ASSET_ID, [DeliveryType::Semi, DeliveryPromise::Hour1, null], ['assetId' => self::ASSET_ID, 'price' => self::PRICE, 'deliveryType' => 'SEMI', 'deliveryPromise' => 'HOUR1']];
        yield 'everything' => [self::INVENTORY_ID, [DeliveryType::Manual, DeliveryPromise::Hour1, 31536000], ['inventoryId' => self::INVENTORY_ID, 'price' => self::PRICE, 'deliveryType' => 'MANUAL', 'deliveryPromise' => 'HOUR1', 'lifetime' => 31536000]];
    }

    /**
     * @param ?array{DeliveryType, ?DeliveryPromise, ?int} $options
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('listings')]
    public function listSendsOneMarketNewAndMapsTheProduct(string $item, ?array $options, array $expected): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->created('new-1')));

        $product = $api->list($item, TestMoney::usd('12.50'), $options === null ? null : new ListingOptions(...$options));

        self::assertSame([['MarketNew', $this->sorted($expected)]], array_map(fn(array $op): array => [$op[0], $this->sorted($op[1])], $http->operations()));
        self::assertSame(['new-1', 'slug-new-1', '12.50'], [$product->id, $product->slug, $product->price->amount->toString()]);
    }

    #[Test]
    public function listWithAnAssetIdOf20DigitsKeepsEveryDigit(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->created('new-1')));

        $api->list('18446744073709551615', TestMoney::usd('1'));

        self::assertSame('18446744073709551615', self::at($http->operations(), 0)[1]['assetId'] ?? null);
        self::assertStringContainsString('"assetId":"18446744073709551615"', $http->lastBody());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badItems(): iterable
    {
        yield 'empty' => [''];
        yield '21 digits' => ['400000000000000000021'];
        yield 'a leading space' => [' 123'];
        yield 'digits with a trailing newline' => ["123\n"];
        yield 'a UUID with a trailing newline' => [self::INVENTORY_ID . "\n"];
        yield 'a name' => ['AK-47 | Redline'];
        yield 'a signed number' => ['-1'];
    }

    #[Test]
    #[DataProvider('badItems')]
    public function listRefusesABadItemBeforeAnyRequest(string $item): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $api->list($item, TestMoney::usd('5'));
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests());
        }
    }

    #[Test]
    public function listRefusesAPriceInAnUnknownCurrencyBeforeAnyRequest(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $api->list(self::ASSET_ID, new Money(Decimal::of('5'), new UnknownEnumValue(Currency::class, 'EUR')));
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Unknown currency "EUR" cannot be sent.', $e->getMessage());
            self::assertSame([], $http->requests());
        }
    }

    // ---- reprice, remove, setLifetimeForAll --------------------------------------------------------------------

    #[Test]
    public function repriceSendsOneMarketEditAndMapsTheProduct(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['market_edit' => $this->liveProduct(MarketNew::class, 'edited-1', 'Result')]])));

        $product = $api->reprice(self::PRODUCT_ID, TestMoney::usd('12.50'));

        self::assertSame([['MarketEdit', ['marketProductId' => self::PRODUCT_ID, 'price' => self::PRICE]]], $http->operations(sortVariables: true));
        self::assertSame('edited-1', $product->id);
    }

    #[Test]
    public function repriceRefusesAnythingButAUuidBeforeAnyRequest(): void
    {
        foreach ([self::ASSET_ID, '', self::PRODUCT_ID . "\n"] as $productId) {
            [$api, $http] = $this->api(new FakeHttpClient());

            try {
                $api->reprice($productId, TestMoney::usd('5'));
                self::fail('Expected an InvalidArgumentException');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $http->requests());
            }
        }
    }

    #[Test]
    public function removeSendsOneMarketRemoveAndMapsTheArchivedProduct(): void
    {
        $removed = ['id' => 'gone-1'] + SyntheticDeal::productHistory('', [], [
            'product' => Result::class,
            'params' => ParamsCSGOInventoryItem::class,
            'description' => DescriptionCSGOSteamItem::class,
        ]);
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['market_remove' => $removed]])));

        $history = $api->remove(self::PRODUCT_ID);

        self::assertSame([['MarketRemove', ['marketProductId' => self::PRODUCT_ID]]], $http->operations());
        self::assertSame(['gone-1', 'M4A4 | Howl'], [$history->id, $history->item?->name]);
    }

    #[Test]
    public function removeRefusesAnythingButAUuidBeforeAnyRequest(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $api->remove(self::ASSET_ID);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests());
        }
    }

    /**
     * SDK_SPEC 3.5 / WHITEMARKET_API.md 4: at least 600 seconds.
     *
     * @return iterable<string, array{int, bool}>
     */
    public static function lifetimesForAll(): iterable
    {
        yield 'one below the minimum' => [599, false];
        yield 'the minimum' => [600, true];
        yield 'a day' => [86400, true];
        yield 'zero' => [0, false];
        yield 'negative' => [-600, false];
    }

    #[Test]
    #[DataProvider('lifetimesForAll')]
    public function setLifetimeForAllValidatesTheMinimumBeforeAnyRequest(int $seconds, bool $accepted): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['market_lifetime_all' => true]])));

        if (!$accepted) {
            try {
                $api->setLifetimeForAll($seconds);
                self::fail('Expected an InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                self::assertSame(\sprintf('The lifetime must be at least 600 seconds, got %d.', $seconds), $e->getMessage());
                self::assertSame([], $http->requests());
            }

            return;
        }

        self::assertTrue($api->setLifetimeForAll($seconds));
        self::assertSame([['MarketLifetimeAll', ['value' => $seconds]]], $http->operations());
    }

    #[Test]
    public function setLifetimeForAllReturnsTheServersAnswerEvenWhenFalse(): void
    {
        [$api] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['market_lifetime_all' => false]])));

        self::assertFalse($api->setLifetimeForAll(600));
    }

    // ---- single mutations are sent at most once -----------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string}> the call ({@see single()}), the operation name
     */
    public static function singleMutations(): iterable
    {
        yield 'list' => ['list', 'MarketNew'];
        yield 'reprice' => ['reprice', 'MarketEdit'];
        yield 'remove' => ['remove', 'MarketRemove'];
        yield 'setLifetimeForAll' => ['setLifetimeForAll', 'MarketLifetimeAll'];
    }

    /**
     * @throws WhiteMarketException
     */
    private function single(SellingApi $api, string $call): mixed
    {
        return match ($call) {
            'list' => $api->list(self::ASSET_ID, TestMoney::usd('5')),
            'reprice' => $api->reprice(self::PRODUCT_ID, TestMoney::usd('5')),
            'remove' => $api->remove(self::PRODUCT_ID),
            'setLifetimeForAll' => $api->setLifetimeForAll(600),
            default => throw new \LogicException('Unknown call ' . $call),
        };
    }

    #[Test]
    #[DataProvider('singleMutations')]
    public function aSingleMutationIsSentOnceAndAServerErrorReachesTheCaller(string $call, string $operation): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::status(200)));

        try {
            $this->single($api, $call);
            self::fail('Expected a ServiceUnavailableException');
        } catch (ServiceUnavailableException) {
            self::assertSame([$operation], array_column($http->operations(), 0), 'no retry, no reconciliation');
        }
    }

    #[Test]
    #[DataProvider('singleMutations')]
    public function aSingleMutationIsSentOnceAndANetworkErrorReachesTheCaller(string $call, string $operation): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::networkError(), FakeHttpClient::status(200)));

        try {
            $this->single($api, $call);
            self::fail('Expected a TransportException');
        } catch (TransportException) {
            self::assertSame([$operation], array_column($http->operations(), 0), 'no retry, no reconciliation');
        }
    }

    #[Test]
    #[DataProvider('singleMutations')]
    public function aRateLimitReachesTheCallerAfterOneRequest(string $call, string $operation): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::status(429, '', ['Retry-After' => '1']), FakeHttpClient::status(200)));

        try {
            $this->single($api, $call);
            self::fail('Expected a RateLimitException');
        } catch (RateLimitException) {
            self::assertSame([$operation], array_column($http->operations(), 0));
        }
    }

    // ---- batches: chunking and request shape --------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, list<int>}> item count (batch size 100), expected chunk sizes
     */
    public static function itemCounts(): iterable
    {
        yield '1 item' => [1, [1]];
        yield '99 items' => [99, [99]];
        yield '100 items' => [100, [100]];
        yield '101 items' => [101, [100, 1]];
        yield '250 items' => [250, [100, 100, 50]];
    }

    /**
     * @return iterable<string, array{string, int, list<int>}> method, item count, expected chunk sizes
     */
    public static function chunkings(): iterable
    {
        foreach (array_keys(self::MANY) as $method) {
            foreach (self::itemCounts() as $name => [$count, $sizes]) {
                yield $method . ', ' . $name => [$method, $count, $sizes];
            }
        }
    }

    /**
     * @param list<int> $sizes
     */
    #[Test]
    #[DataProvider('chunkings')]
    public function sendsOneRequestPerChunkInOrderAndReindexesToTheCallersList(string $method, int $count, array $sizes): void
    {
        [$operation, , $namespace] = $this->spec($method);
        $responses = [];
        $offset = 0;
        foreach ($sizes as $k => $size) {
            $responses[] = $this->answer($namespace, $this->rowsFor($namespace, $size, 'c' . $k, $offset));
            $offset += $size;
        }
        [$api, $http] = $this->api(new FakeHttpClient(...$responses));

        $results = $this->many($api, $method, $count);

        self::assertSame(array_fill(0, \count($sizes), $operation), array_column($http->operations(), 0));
        $sent = array_map(fn(array $variables): array => $this->identities($method, $variables), array_column($http->operations(), 1));
        self::assertSame(array_map(static fn(int $size): int => $size, $sizes), array_map(count(...), $sent));
        self::assertSame(array_map(static fn(int $i): string => self::identity($method, $i), range(0, $count - 1)), array_merge(...$sent), 'every item exactly once, in the caller\'s order');
        self::assertSame(range(0, $count - 1), array_map(static fn(BatchItemResult $r): int => $r->index, $results));
        self::assertSame([BatchOutcome::Succeeded], array_values(array_unique(array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $results), \SORT_REGULAR)));
        self::assertSame(
            $method === 'listMany'
                ? array_merge(...array_map(static fn(int $size, int $k): array => array_map(static fn(int $i): string => \sprintf('c%d-%d', $k, $i), range(0, $size - 1)), $sizes, array_keys($sizes)))
                : array_map(static fn(int $i): string => self::identity($method, $i), range(0, $count - 1)),
            array_map(static fn(BatchItemResult $r): ?string => $r->product?->id, $results),
        );
    }

    #[Test]
    #[DataProvider('manyMethods')]
    public function anEmptyListSendsNothing(string $method): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        self::assertSame([], $this->many($api, $method, 0));
        self::assertSame([], $http->requests());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function manyMethods(): iterable
    {
        foreach (array_keys(self::MANY) as $method) {
            yield $method => [$method];
        }
    }

    #[Test]
    public function listManyAppliesTheOptionsToEveryChunkAndRoutesInventoryAndAssetIds(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->answer(MarketNewMultiple::class, $this->rowsFor(MarketNewMultiple::class, 2, 'a')), $this->answer(MarketNewMultiple::class, $this->rowsFor(MarketNewMultiple::class, 1, 'b'))), 2);

        $api->listMany(
            [new Listing(self::INVENTORY_ID, TestMoney::usd('1.5')), new Listing(self::ASSET_ID, TestMoney::usd('2')), new Listing('18446744073709551615', TestMoney::usd('3'))],
            new ListingOptions(DeliveryType::Manual, DeliveryPromise::Hour1, 3600),
        );

        $options = ['deliveryType' => 'MANUAL', 'deliveryPromise' => 'HOUR1', 'lifetime' => 3600];
        self::assertSame(
            $this->sorted([
                ['MarketNewMultiple', $options + ['items' => [
                    ['inventoryId' => self::INVENTORY_ID, 'price' => ['currency' => 'USD', 'value' => '1.5']],
                    ['assetId' => self::ASSET_ID, 'price' => ['currency' => 'USD', 'value' => '2']],
                ]]],
                ['MarketNewMultiple', $options + ['items' => [
                    ['assetId' => '18446744073709551615', 'price' => ['currency' => 'USD', 'value' => '3']],
                ]]],
            ]),
            array_map(fn(array $op): array => [$op[0], $this->sorted($op[1])], $http->operations()),
        );
    }

    #[Test]
    public function listManyWithoutOptionsSendsSemiAndNothingElse(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->answer(MarketNewMultiple::class, $this->rowsFor(MarketNewMultiple::class, 1, 'a'))));

        $api->listMany([new Listing(self::ASSET_ID, TestMoney::usd('2'))]);

        $variables = self::at($http->operations(), 0)[1];
        self::assertSame('SEMI', $variables['deliveryType'] ?? null);
        self::assertArrayNotHasKey('deliveryPromise', $variables);
        self::assertArrayNotHasKey('lifetime', $variables);
    }

    #[Test]
    public function repriceManyRoutesListingIdsAndAssetIds(): void
    {
        $ns = MarketEditMultiple::class;
        [$api, $http] = $this->api(new FakeHttpClient($this->answer($ns, [
            $this->row($ns, 0, true, $this->liveProduct($ns, self::PRODUCT_ID)),
            $this->row($ns, 1, true, $this->liveProduct($ns, 'any')),
        ])));

        $api->repriceMany([new PriceChange(self::PRODUCT_ID, TestMoney::usd('1.5')), new PriceChange(self::ASSET_ID, TestMoney::usd('2'))]);

        self::assertSame(
            $this->sorted([['MarketEditMultiple', ['items' => [
                ['productId' => self::PRODUCT_ID, 'price' => ['currency' => 'USD', 'value' => '1.5']],
                ['assetId' => self::ASSET_ID, 'price' => ['currency' => 'USD', 'value' => '2']],
            ]]]]),
            array_map(fn(array $op): array => [$op[0], $this->sorted($op[1])], $http->operations()),
        );
    }

    #[Test]
    public function removeManyRefusesABadIdAtTheEndOfALongListBeforeTheFirstRequest(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());
        $ids = array_map(static fn(int $i): string => self::identity('removeMany', $i), range(0, 299));
        $ids[250] = 'not-a-uuid';
        $ids = array_values($ids);

        try {
            $api->removeMany($ids);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests(), 'chunks 1 and 2 must not go out');
        }
    }

    #[Test]
    public function removeManyRefusesAnAssetId(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $api->removeMany([self::PRODUCT_ID, self::ASSET_ID]);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $http->requests());
        }
    }

    // ---- batches: what the server reports ----------------------------------------------------------------------

    #[Test]
    #[DataProvider('manyMethods')]
    public function matchesRowsToItemsByIndexWhateverTheOrderOfTheAnswer(string $method): void
    {
        [, , $namespace] = $this->spec($method);
        $rows = $this->rowsFor($namespace, 2, 'late', 2);
        $first = $this->rowsFor($namespace, 2, 'early');
        [$api] = $this->api(new FakeHttpClient($this->answer($namespace, array_reverse($first)), $this->answer($namespace, array_reverse($rows))), 2);

        $results = $this->many($api, $method, 4);

        self::assertSame(
            $method === 'listMany' ? ['early-0', 'early-1', 'late-0', 'late-1'] : array_map(static fn(int $i): string => self::identity($method, $i), [0, 1, 2, 3]),
            array_map(static fn(BatchItemResult $r): ?string => $r->product?->id, $results),
        );
        self::assertSame([0, 1, 2, 3], array_map(static fn(BatchItemResult $r): int => $r->index, $results));
    }

    #[Test]
    public function aFailedRowCarriesTheServersCategoryAndMessageAndDoesNotRaise(): void
    {
        $ns = MarketNewMultiple::class;
        $rows = [
            $this->row($ns, 1, false, null, 'market.product.not_tradable', 'Item is not tradable'),
            $this->row($ns, 0, true, $this->liveProduct($ns, 'ok-0')),
            $this->row($ns, 2, false, null),
        ];
        [$api] = $this->api(new FakeHttpClient($this->answer($ns, $rows)));

        $results = $this->many($api, 'listMany', 3);

        self::assertSame(
            [[0, BatchOutcome::Succeeded, null, null], [1, BatchOutcome::Failed, 'market.product.not_tradable', 'Item is not tradable'], [2, BatchOutcome::Failed, null, null]],
            array_map(static fn(BatchItemResult $r): array => [$r->index, $r->outcome, $r->errorCategory, $r->errorMessage], $results),
        );
        self::assertSame([null, null], [self::at($results, 1)->product, self::at($results, 2)->product]);
        self::assertInstanceOf(Product::class, self::at($results, 0)->product);
    }

    #[Test]
    public function aRowWithTheProductOfEachKindIsMappedToItsModel(): void
    {
        $ns = MarketNewMultiple::class;
        $rows = [
            $this->row($ns, 0, true, $this->liveProduct($ns, 'live')),
            $this->row($ns, 1, true, $this->historyProduct($ns, 'archived')),
            $this->row($ns, 2, true, ['__typename' => 'SomethingNew', 'whatever' => 1]),
            $this->row($ns, 3, true, null),
        ];
        [$api] = $this->api(new FakeHttpClient($this->answer($ns, $rows)));

        $results = $this->many($api, 'listMany', 4);

        $live = self::at($results, 0)->product;
        $archived = self::at($results, 1)->product;
        self::assertInstanceOf(Product::class, $live);
        self::assertSame('live', $live->id);
        self::assertInstanceOf(ProductHistory::class, $archived);
        self::assertSame('archived', $archived->id);
        self::assertNull(self::at($results, 2)->product);
        self::assertNull(self::at($results, 3)->product);
        self::assertSame([BatchOutcome::Succeeded], array_values(array_unique(array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $results), \SORT_REGULAR)));
    }

    #[Test]
    public function aProductWithAnUnknownEnumValueIsMappedAndTheRowStaysSucceeded(): void
    {
        $ns = MarketNewMultiple::class;
        $teleport = $this->liveProduct($ns, 'teleport');
        $teleport['deliveryType'] = 'TELEPORT';
        [$api] = $this->api(new FakeHttpClient($this->answer($ns, [$this->row($ns, 0, true, $teleport)])));

        $results = $this->many($api, 'listMany', 1);

        $product = self::at($results, 0)->product;
        self::assertInstanceOf(Product::class, $product);
        self::assertInstanceOf(UnknownEnumValue::class, $product->deliveryType);
        self::assertSame('TELEPORT', $product->deliveryType->value);
        self::assertSame(BatchOutcome::Succeeded, self::at($results, 0)->outcome);
    }

    #[Test]
    public function anUnknownErrorCategoryIsCarriedVerbatimAndTheRowFails(): void
    {
        $ns = MarketNewMultiple::class;
        [$api] = $this->api(new FakeHttpClient($this->answer($ns, [$this->row($ns, 0, false, null, 'market.brand_new.category', 'Something new')])));

        $result = self::at($this->many($api, 'listMany', 1), 0);

        self::assertSame(BatchOutcome::Failed, $result->outcome);
        self::assertSame('market.brand_new.category', $result->errorCategory);
        self::assertSame('Something new', $result->errorMessage);
    }

    #[Test]
    public function aProductThatCannotBeMappedKeepsTheOutcomeTheServerReported(): void
    {
        $ns = MarketNewMultiple::class;
        $broken = $this->liveProduct($ns, 'broken');
        $broken['price'] = ['currency' => 'USD', 'value' => 'not-a-number'];
        [$api] = $this->api(new FakeHttpClient($this->answer($ns, [$this->row($ns, 0, true, $broken), $this->row($ns, 1, false, $broken, 'x', 'y')])));

        $results = $this->many($api, 'listMany', 2);

        self::assertSame([BatchOutcome::Succeeded, BatchOutcome::Failed], array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $results));
        self::assertSame([null, null], array_map(static fn(BatchItemResult $r): mixed => $r->product, $results));
    }

    #[Test]
    #[DataProvider('manyMethods')]
    public function anItemWithoutARowIsUnknownAndLaterChunksStillGoOutBeforeTheExceptionIsRaised(string $method): void
    {
        [, , $namespace] = $this->spec($method);
        $firstChunk = $this->rowsFor($namespace, 2, 'a');
        [$api, $http] = $this->api(new FakeHttpClient($this->answer($namespace, [self::at($firstChunk, 0)]), $this->answer($namespace, $this->rowsFor($namespace, 2, 'b', 2))), 2);

        try {
            $this->many($api, $method, 4);
            self::fail('Expected a BatchIncompleteException');
        } catch (BatchIncompleteException $e) {
            self::assertCount(2, $http->requests(), 'the later chunk was sent');
            self::assertSame(
                [BatchOutcome::Succeeded, BatchOutcome::Unknown, BatchOutcome::Succeeded, BatchOutcome::Succeeded],
                array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $e->results),
            );
            self::assertSame([0, 1, 2, 3], array_map(static fn(BatchItemResult $r): int => $r->index, $e->results));
            self::assertNull($e->getPrevious(), 'nothing failed, the server only left an item out');
            self::assertSame('Batch incomplete: 3 succeeded, 0 failed, 1 unknown, 0 not sent.', $e->getMessage());
        }
    }

    // ---- batches: a request that fails --------------------------------------------------------------------------

    /**
     * @return iterable<string, array{ResponseInterface|\Throwable|\Closure(RequestInterface): ClientExceptionInterface, BatchOutcome, class-string<\Throwable>}> what chunk 2 gets,
     *                                                                                                                                                            the outcome of its items, the exception it raises
     */
    public static function failingChunks(): iterable
    {
        $error = static fn(array $path): array => ['message' => 'Failure', 'path' => $path, 'extensions' => ['category' => 'internal']];

        yield 'HTTP 503 (ambiguous)' => [FakeHttpClient::status(503), BatchOutcome::Unknown, ServiceUnavailableException::class];
        yield 'a network error (ambiguous)' => [FakeHttpClient::networkErrorHoldingTheRequest(), BatchOutcome::Unknown, TransportException::class];
        yield 'an AuthenticationException thrown by the client (ambiguous)' => [new AuthenticationException(401, ''), BatchOutcome::Unknown, TransportException::class];
        yield 'an unreadable body (ambiguous)' => [FakeHttpClient::status(200, 'not json'), BatchOutcome::Unknown, DecodeException::class];
        yield 'HTTP 429 (rejected)' => [FakeHttpClient::status(429), BatchOutcome::NotSent, RateLimitException::class];
        yield 'HTTP 400 (rejected)' => [FakeHttpClient::status(400, 'bad'), BatchOutcome::NotSent, BadRequestException::class];
        yield 'GraphQL root-path error, data null (ambiguous: the resolver may have acted on some items)' => [FakeHttpClient::json(['data' => null, 'errors' => [$error(['market_new_multiple'])]]), BatchOutcome::Unknown, GraphQLException::class];
        yield 'GraphQL root-path error, root field null (ambiguous)' => [FakeHttpClient::json(['data' => ['market_new_multiple' => null], 'errors' => [$error(['market_new_multiple'])]]), BatchOutcome::Unknown, GraphQLException::class];
        yield 'GraphQL pathless and root-path errors (ambiguous)' => [FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Rejected'], $error(['market_new_multiple'])]]), BatchOutcome::Unknown, GraphQLException::class];
        yield 'GraphQL request error without a path, no data key (rejected)' => [FakeHttpClient::json(['errors' => [['message' => 'Rejected']]]), BatchOutcome::NotSent, GraphQLException::class];
        yield 'GraphQL pathless error, data null (execution started, ambiguous)' => [FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Rejected']]]), BatchOutcome::Unknown, GraphQLException::class];
        yield 'GraphQL nested-path error (ambiguous)' => [FakeHttpClient::json(['data' => ['market_new_multiple' => null], 'errors' => [$error(['market_new_multiple', 0, 'product'])]]), BatchOutcome::Unknown, GraphQLException::class];
        yield 'GraphQL error next to a result (ambiguous)' => [FakeHttpClient::json(['data' => ['market_new_multiple' => []], 'errors' => [$error(['market_new_multiple'])]]), BatchOutcome::Unknown, GraphQLException::class];
    }

    /**
     * @param ResponseInterface|\Throwable|\Closure(RequestInterface): ClientExceptionInterface $failure
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataProvider('failingChunks')]
    public function aFailingChunkStopsTheBatchKeepsEarlierResultsAndNeverSendsLaterChunks(ResponseInterface|\Throwable|\Closure $failure, BatchOutcome $expected, string $exception): void
    {
        $ns = MarketNewMultiple::class;
        [$api, $http] = $this->api(new FakeHttpClient($this->answer($ns, $this->rowsFor($ns, 2, 'a')), $failure, $this->answer($ns, $this->rowsFor($ns, 2, 'c'))), 2);

        try {
            $this->many($api, 'listMany', 6);
            self::fail('Expected a BatchIncompleteException');
        } catch (BatchIncompleteException $e) {
            self::assertCount(2, $http->requests(), 'chunk 3 is never sent, chunk 2 only once');
            self::assertSame(
                [BatchOutcome::Succeeded, BatchOutcome::Succeeded, $expected, $expected, BatchOutcome::NotSent, BatchOutcome::NotSent],
                array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $e->results),
            );
            self::assertSame([0, 1, 2, 3, 4, 5], array_map(static fn(BatchItemResult $r): int => $r->index, $e->results));
            self::assertSame(['a-0', 'a-1', null, null, null, null], array_map(static fn(BatchItemResult $r): ?string => $r->product?->id, $e->results));
            self::assertInstanceOf($exception, $e->getPrevious());
        }
    }

    /**
     * The identity a returned row carries, against the item requested at its position. `id` is the product id, `asset` and
     * `inventory` are the ids of the product's item; null leaves the field out, `product: false` is a row without one.
     *
     * @return iterable<string, array{string, list<string>, list<array{id?: string, asset?: string, inventory?: string, product?: bool}>, bool}> method, requested items, rows by position, whether every row matches
     */
    public static function rowIdentities(): iterable
    {
        $upper = strtoupper(self::PRODUCT_ID);
        $other = '11111111-1111-4111-8111-111111111111';

        yield 'remove: ids match' => ['removeMany', [self::PRODUCT_ID, $other], [['id' => self::PRODUCT_ID], ['id' => $other]], true];
        yield 'remove: UUID compared case-insensitively' => ['removeMany', [self::PRODUCT_ID, $other], [['id' => $upper], ['id' => $other]], true];
        yield 'remove: another listing id at position 1' => ['removeMany', [self::PRODUCT_ID, $other], [['id' => self::PRODUCT_ID], ['id' => self::INVENTORY_ID]], false];
        yield 'remove: the answer shifted by one (1-based server, partial)' => ['removeMany', [self::PRODUCT_ID, $other], [['id' => $other], ['id' => self::PRODUCT_ID]], false];
        yield 'remove: a row without a product skips the check' => ['removeMany', [self::PRODUCT_ID, $other], [['product' => false], ['id' => $other]], true];
        yield 'remove: one good row does not save a mismatching one' => ['removeMany', [self::PRODUCT_ID, $other], [['id' => self::PRODUCT_ID], ['id' => 'x']], false];
        yield 'reprice by id: ids match, case-insensitive' => ['repriceMany', [self::PRODUCT_ID, $other], [['id' => $upper], ['id' => $other]], true];
        yield 'reprice by id: another listing' => ['repriceMany', [self::PRODUCT_ID, $other], [['id' => self::PRODUCT_ID], ['id' => self::INVENTORY_ID]], false];
        yield 'reprice by id: a row without a product skips the check' => ['repriceMany', [self::PRODUCT_ID, $other], [['id' => self::PRODUCT_ID], ['product' => false]], true];
        yield 'reprice by asset id: assets match' => ['repriceMany', [self::ASSET_ID, '18446744073709551615'], [['id' => 'p0', 'asset' => self::ASSET_ID], ['id' => 'p1', 'asset' => '18446744073709551615']], true];
        yield 'reprice by asset id: another asset' => ['repriceMany', [self::ASSET_ID, '18446744073709551615'], [['id' => 'p0', 'asset' => self::ASSET_ID], ['id' => 'p1', 'asset' => '18446744073709551614']], false];
        yield 'reprice by asset id: a product without an item skips the check' => ['repriceMany', [self::ASSET_ID, '18446744073709551615'], [['id' => 'p0'], ['id' => 'p1']], true];
        yield 'list by asset id: assets match' => ['listMany', [self::ASSET_ID, '18446744073709551615'], [['id' => 'p0', 'asset' => self::ASSET_ID], ['id' => 'p1', 'asset' => '18446744073709551615']], true];
        yield 'list by asset id: another asset' => ['listMany', [self::ASSET_ID, '18446744073709551615'], [['id' => 'p0', 'asset' => self::ASSET_ID], ['id' => 'p1', 'asset' => self::ASSET_ID]], false];
        yield 'list by asset id: a product without an item skips the check' => ['listMany', [self::ASSET_ID, '18446744073709551615'], [['id' => 'p0'], ['id' => 'p1']], true];
        yield 'list by asset id: a row without a product skips the check' => ['listMany', [self::ASSET_ID, '18446744073709551615'], [['product' => false], ['product' => false]], true];
        yield 'list by inventory id: ids match, case-insensitive' => ['listMany', [self::INVENTORY_ID, $other], [['id' => 'p0', 'inventory' => strtoupper(self::INVENTORY_ID)], ['id' => 'p1', 'inventory' => $other]], true];
        yield 'list by inventory id: another inventory item' => ['listMany', [self::INVENTORY_ID, $other], [['id' => 'p0', 'inventory' => self::INVENTORY_ID], ['id' => 'p1', 'inventory' => self::INVENTORY_ID]], false];
    }

    /**
     * @param list<string> $items
     * @param list<array{id?: string, asset?: string, inventory?: string, product?: bool}> $specs
     */
    #[Test]
    #[DataProvider('rowIdentities')]
    public function aRowIsCheckedAgainstTheItemRequestedAtItsPosition(string $method, array $items, array $specs, bool $matches): void
    {
        [, , $ns] = $this->spec($method);
        $rows = [];
        foreach ($specs as $position => $spec) {
            $product = null;
            if (($spec['product'] ?? true) === true) {
                $id = $spec['id'] ?? throw new \LogicException('a spec with a product needs an id');
                $product = $ns === MarketRemoveMultiple::class ? $this->historyProduct($ns, $id) : $this->liveProduct($ns, $id, item: $spec);
            }
            $rows[] = $this->row($ns, $position, true, $product);
        }
        // Three items, two per request: a rejected chunk must keep the later one unsent.
        [$api, $http] = $this->api(new FakeHttpClient($this->answer($ns, $rows), $this->answer($ns, [$this->row($ns, 0, true, null)])), 2);
        $all = [...$items, self::identity($method, 2)];

        try {
            $results = $this->callWith($api, $method, $all);
            self::assertTrue($matches, 'a mismatching row must not be accepted');
            self::assertSame([BatchOutcome::Succeeded, BatchOutcome::Succeeded], \array_slice(array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $results), 0, 2));
        } catch (BatchIncompleteException $e) {
            self::assertFalse($matches, 'matching rows must be accepted');
            self::assertCount(1, $http->requests(), 'the later chunk is never sent');
            self::assertSame([BatchOutcome::Unknown, BatchOutcome::Unknown, BatchOutcome::NotSent], array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $e->results));
            $cause = $e->getPrevious();
            self::assertInstanceOf(DecodeException::class, $cause);
            self::assertMatchesRegularExpression('/^market_\w+_multiple\[\d\]\.product\.(id|item)/D', $cause->path);
        }
    }

    /**
     * @param list<string> $items
     *
     * @return list<BatchItemResult>
     */
    private function callWith(SellingApi $api, string $method, array $items): array
    {
        return match ($method) {
            'listMany' => $api->listMany(array_map(static fn(string $id): Listing => new Listing($id, TestMoney::usd('5')), $items)),
            'repriceMany' => $api->repriceMany(array_map(static fn(string $id): PriceChange => new PriceChange($id, TestMoney::usd('5')), $items)),
            'removeMany' => $api->removeMany($items),
            default => throw new \LogicException('Unknown method ' . $method),
        };
    }

    #[Test]
    #[DataProvider('manyMethods')]
    public function theFirstChunkFailingLeavesEveryItemNotSentOrUnknownAccordingToTheClassification(string $method): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::status(200)), 2);

        try {
            $this->many($api, $method, 3);
            self::fail('Expected a BatchIncompleteException');
        } catch (BatchIncompleteException $e) {
            self::assertCount(1, $http->requests());
            self::assertSame(
                [BatchOutcome::Unknown, BatchOutcome::Unknown, BatchOutcome::NotSent],
                array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $e->results),
            );
        }
    }

    /**
     * @return iterable<string, array{list<array{index: int}>}> rows of the answer of chunk 2 (two items), each with the index it claims
     */
    public static function unusableAnswers(): iterable
    {
        yield 'an index past the chunk' => [[['index' => 0], ['index' => 2]]];
        yield 'an index equal to the chunk size' => [[['index' => 2], ['index' => 1]]];
        yield 'a negative index' => [[['index' => 0], ['index' => -1]]];
        yield 'an index repeated' => [[['index' => 1], ['index' => 1]]];
        yield 'more rows than items' => [[['index' => 0], ['index' => 1], ['index' => 0]]];
    }

    /**
     * @param list<array{index: int}> $claims
     */
    #[Test]
    #[DataProvider('unusableAnswers')]
    public function anIndexOutOfRangeOrRepeatedMakesTheChunkUnknownBecauseTheMutationAlreadyRan(array $claims): void
    {
        $ns = MarketNewMultiple::class;
        $rows = array_map(fn(array $claim): array => $this->row($ns, $claim['index'], true, $this->liveProduct($ns, 'x')), $claims);
        [$api, $http] = $this->api(new FakeHttpClient($this->answer($ns, $this->rowsFor($ns, 2, 'a')), $this->answer($ns, $rows), $this->answer($ns, $this->rowsFor($ns, 2, 'c'))), 2);

        try {
            $this->many($api, 'listMany', 6);
            self::fail('Expected a BatchIncompleteException');
        } catch (BatchIncompleteException $e) {
            self::assertCount(2, $http->requests(), 'chunk 3 is never sent');
            self::assertSame(
                [BatchOutcome::Succeeded, BatchOutcome::Succeeded, BatchOutcome::Unknown, BatchOutcome::Unknown, BatchOutcome::NotSent, BatchOutcome::NotSent],
                array_map(static fn(BatchItemResult $r): BatchOutcome => $r->outcome, $e->results),
            );
            $cause = $e->getPrevious();
            self::assertInstanceOf(DecodeException::class, $cause);
            self::assertMatchesRegularExpression('/^market_new_multiple\[\d\]\.index$/D', $cause->path);
        }
    }

    // ---- myProducts --------------------------------------------------------------------------------------------

    /**
     * @param list<string> $slugs
     *
     * @return array<string, mixed> the `data` of a `market_my` response
     */
    private function myPage(array $slugs, ?string $endCursor, bool $hasNextPage): array
    {
        return ['market_my' => [
            'edges' => array_map(
                static fn(string $slug): array => ['cursor' => 'c-' . $slug, 'node' => SyntheticResult::of(EdgesNode::class, ['id' => 'id-' . $slug, 'slug' => $slug])],
                $slugs,
            ),
            'pageInfo' => SyntheticResult::of(PageInfo::class, ['endCursor' => $endCursor, 'hasNextPage' => $hasNextPage]),
            'totalCount' => 3,
        ]];
    }

    #[Test]
    public function myProductsSendsMarketMyWithThePageAndTheFilterAndMapsThePage(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => $this->myPage(['ak-1', 'awp-2'], 'cursor-2', true)])));

        $page = $api->myProducts(new ProductFilter(app: App::Csgo, nameStrict: true), new PageRequest(50, 'cursor-1'));

        self::assertSame([['MarketMy', ['forwardPagination' => ['after' => 'cursor-1', 'first' => 50], 'search' => ['appId' => 'CSGO', 'nameStrict' => true]]]], array_map(fn(array $op): array => [$op[0], $this->sorted($op[1])], $http->operations()));
        self::assertSame(['ak-1', 'awp-2'], array_map(static fn(Product $product): string => $product->slug, $page->items));
        self::assertSame(['cursor-2', true, 3], [$page->endCursor, $page->hasNextPage, $page->totalCount]);
    }

    #[Test]
    public function myProductsSendsARawInputExactlyAndNothingWithoutArguments(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => $this->myPage([], null, false)]), FakeHttpClient::json(['data' => $this->myPage([], null, false)])));

        $api->myProducts();
        $api->myProducts(new MarketProductSearchInput(appId: SteamApp::TF2, csgoFloatFrom: '0.0700'));

        self::assertSame([[], ['search' => ['appId' => 'TF2', 'csgoFloatFrom' => '0.0700']]], array_column($http->operations(), 1));
    }

    #[Test]
    public function iterateMyProductsIsLazyFollowsCursorsAndKeysAcrossPages(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(
            FakeHttpClient::json(['data' => $this->myPage(['a', 'b'], 'c2', true)]),
            FakeHttpClient::json(['data' => $this->myPage(['c'], 'c3', false)]),
        ));

        $items = $api->iterateMyProducts(new ProductFilter(nameStrict: true), 2);
        self::assertCount(0, $http->requests(), 'created, not started');

        $keyed = [];
        foreach ($items as $key => $product) {
            $keyed[$key] = $product->slug;
            if ($key === 1) {
                self::assertCount(1, $http->requests(), 'page 2 only after page 1 is consumed');
            }
        }

        self::assertSame([0 => 'a', 1 => 'b', 2 => 'c'], $keyed);
        self::assertSame(
            [
                ['forwardPagination' => ['first' => 2], 'search' => ['nameStrict' => true]],
                ['forwardPagination' => ['after' => 'c2', 'first' => 2], 'search' => ['nameStrict' => true]],
            ],
            array_map($this->sorted(...), array_column($http->operations(), 1)),
        );
    }

    #[Test]
    public function iterateMyProductsNamesTheListInTheErrorPathOfAMissingCursor(): void
    {
        [$api] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => $this->myPage(['a'], null, true)])));

        try {
            iterator_to_array($api->iterateMyProducts(), false);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('market_my.pageInfo.endCursor', $e->path);
        }
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    /**
     * @template T
     *
     * @param array<int, T> $list
     *
     * @return T
     */
    private function at(array $list, int $i): mixed
    {
        return $list[$i] ?? throw new \LogicException('No item #' . $i);
    }

    /**
     * Sorts the keys of `$variables` and of the objects inside it (a list keeps its order), so a comparison does not
     * depend on the order they were built in.
     *
     * @param array<mixed> $variables
     *
     * @return array<mixed>
     */
    private function sorted(array $variables): array
    {
        foreach ($variables as $key => $value) {
            if (\is_array($value)) {
                $variables[$key] = $this->sorted($value);
            }
        }
        if (!array_is_list($variables)) {
            ksort($variables);
        }

        return $variables;
    }

    /**
     * @return array{string, string, class-string} operation name, root field, generated namespace
     */
    private function spec(string $method): array
    {
        return self::MANY[$method] ?? throw new \LogicException('Unknown method ' . $method);
    }

    /**
     * @return class-string
     */
    private function cls(string $namespace, string $name): string
    {
        /** @var class-string $class */
        $class = $namespace . '\\' . $name;

        return $class;
    }

    /**
     * The identity of item `$i` of a `*Many` call: an asset id, a listing id or a listing id again.
     */
    private static function identity(string $method, int $i): string
    {
        return $method === 'listMany' ? \sprintf('4%019d', $i) : \sprintf('00000000-0000-4000-8000-%012d', $i);
    }

    /**
     * @return list<BatchItemResult>
     */
    private function many(SellingApi $api, string $method, int $count): array
    {
        $identities = array_map(static fn(int $i): string => self::identity($method, $i), $count === 0 ? [] : range(0, $count - 1));

        return match ($method) {
            'listMany' => $api->listMany(array_map(static fn(string $id): Listing => new Listing($id, TestMoney::usd('5')), $identities)),
            'repriceMany' => $api->repriceMany(array_map(static fn(string $id): PriceChange => new PriceChange($id, TestMoney::usd('5')), $identities)),
            'removeMany' => $api->removeMany($identities),
            default => throw new \LogicException('Unknown method ' . $method),
        };
    }

    /**
     * The identities of the items sent in one request.
     *
     * @param array<string, mixed> $variables
     *
     * @return list<string>
     */
    private function identities(string $method, array $variables): array
    {
        $items = $variables['items'] ?? null;
        self::assertIsArray($items);

        return array_values(array_map(static function (mixed $item) use ($method): string {
            if ($method === 'removeMany') {
                self::assertIsString($item);

                return $item;
            }
            self::assertIsArray($item);
            $id = $item[$method === 'listMany' ? 'assetId' : 'productId'] ?? null;
            self::assertIsString($id);

            return $id;
        }, $items));
    }

    private function created(string $id): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['market_new' => $this->liveProduct(MarketNew::class, $id, 'Result')]]);
    }

    /**
     * A `MarketProduct` as the generated classes of `$namespace` read it. For a single mutation (`$resultClass`
     * 'Result') the class is the operation's own `Result`.
     *
     * @param array{asset?: string, inventory?: string, ...} $item the ids of the product's item; no item when both are absent
     *
     * @return array<string, mixed>
     */
    private function liveProduct(string $namespace, string $id, string $resultClass = 'ProductMarketProduct', array $item = []): array
    {
        $overrides = ['id' => $id, 'slug' => 'slug-' . $id, 'price' => self::PRICE];
        if (isset($item['asset']) || isset($item['inventory'])) {
            $itemOverrides = array_filter(['assetId' => $item['asset'] ?? null, 'id' => $item['inventory'] ?? null], static fn(?string $v): bool => $v !== null);
            $overrides['item'] = SyntheticResult::typed('CSGOInventoryItem', $this->cls($namespace, 'ProductMarketProductItemCSGOInventoryItem'), $itemOverrides);
        }

        return $resultClass === 'Result'
            ? SyntheticResult::of($this->cls($namespace, 'Result'), $overrides)
            : SyntheticResult::typed('MarketProduct', $this->cls($namespace, $resultClass), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function historyProduct(string $namespace, string $id): array
    {
        return ['__typename' => 'MarketProductHistory', 'id' => $id] + SyntheticDeal::productHistory('', [], [
            'product' => $this->cls($namespace, 'ProductMarketProductHistory'),
            'params' => $this->cls($namespace, 'ProductMarketProductHistoryParamsCSGOInventoryItem'),
            'description' => $this->cls($namespace, 'ProductMarketProductHistoryDescriptionCSGOSteamItem'),
        ]);
    }

    /**
     * One `MarketProductResult` row. The product of a `*Many` answer is a live listing, except for removals, which
     * answer with the archived record.
     *
     * @param class-string $namespace
     * @param array<string, mixed>|null $product
     *
     * @return array<string, mixed>
     */
    private function row(string $namespace, int $index, bool $created, ?array $product, ?string $category = null, ?string $message = null): array
    {
        return [
            'index' => $index,
            'isCreated' => $created,
            'product' => $product,
            'errorCategory' => $category,
            'errorMessage' => $message,
        ] + SyntheticResult::of($this->cls($namespace, 'Result'));
    }

    /**
     * Rows for positions 0..$size-1 of the chunk that starts at item `$offset`, each succeeded. The product answers for
     * the item of its position: a listing id (reprice) or an archived record (remove) carries the item's own id, a new
     * listing (list) has the id `<tag>-<position>` and no item.
     *
     * @param class-string $namespace
     *
     * @return list<array<string, mixed>>
     */
    private function rowsFor(string $namespace, int $size, string $tag, int $offset = 0): array
    {
        $rows = [];
        for ($i = 0; $i < $size; ++$i) {
            $id = match ($namespace) {
                MarketEditMultiple::class => self::identity('repriceMany', $offset + $i),
                MarketRemoveMultiple::class => self::identity('removeMany', $offset + $i),
                default => \sprintf('%s-%d', $tag, $i),
            };
            $rows[] = $this->row($namespace, $i, true, $namespace === MarketRemoveMultiple::class ? $this->historyProduct($namespace, $id) : $this->liveProduct($namespace, $id));
        }

        return $rows;
    }

    /**
     * @param class-string $namespace
     * @param list<array<string, mixed>> $rows
     */
    private function answer(string $namespace, array $rows): ResponseInterface
    {
        $field = match ($namespace) {
            MarketNewMultiple::class => 'market_new_multiple',
            MarketEditMultiple::class => 'market_edit_multiple',
            MarketRemoveMultiple::class => 'market_remove_multiple',
            default => throw new \LogicException('Unknown namespace ' . $namespace),
        };

        return FakeHttpClient::json(['data' => [$field => $rows]]);
    }
}
