<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Deals;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Deals\DealHistoryFilter;
use WhiteMarket\Sdk\Deals\DealsApi;
use WhiteMarket\Sdk\Deals\Until;
use WhiteMarket\Sdk\Errors\DealWaitTimeoutException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GeneralGraphQLException;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Input\MarkerDealHistorySearchInput;
use WhiteMarket\Sdk\Generated\Operation\Mutation\DealCancelSendingOffer;
use WhiteMarket\Sdk\Generated\Operation\Mutation\DealRequestCancel;
use WhiteMarket\Sdk\Generated\Operation\Mutation\DealSteamOfferSent;
use WhiteMarket\Sdk\Generated\Operation\Query\DealBuyerList;
use WhiteMarket\Sdk\Generated\Operation\Query\DealBuyerStatuses\Result as StatusResult;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\EdgesNode;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\EdgesNodeProduct;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\PageInfo;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\DealItem;
use WhiteMarket\Sdk\Generated\Operation\Query\DealSellerList;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\Event\DealEvent;
use WhiteMarket\Sdk\Live\Live;
use WhiteMarket\Sdk\Live\LiveHandlers;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveSession;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\Deal;
use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\DealState;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\OfferCancelReason;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\FixedRandomSource;
use WhiteMarket\Sdk\Tests\Support\LiveFixture;
use WhiteMarket\Sdk\Tests\Support\ManualClock;
use WhiteMarket\Sdk\Tests\Support\MemoryLogger;
use WhiteMarket\Sdk\Tests\Support\ScriptedConnection;
use WhiteMarket\Sdk\Tests\Support\ScriptedTokens;
use WhiteMarket\Sdk\Tests\Support\SyntheticDeal;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Transport\RetryPolicy;

#[CoversClass(DealsApi::class)]
final class DealsApiTest extends TestCase
{
    private const ID = '0f6d2b8e-5a7c-4e1f-9b3d-6c2a8e4f1d70';

    private const OTHER_ID = '7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13';

    private const HISTORY_CLASSES = [
        'result' => EdgesNode::class,
        'product' => EdgesNodeProduct::class,
        'params' => EdgesNode::class . 'ProductParamsCSGOInventoryItem',
        'description' => EdgesNode::class . 'ProductDescriptionCSGOSteamItem',
    ];

    /**
     * @return array{DealsApi, FakeHttpClient}
     */
    private function api(FakeHttpClient $http, RetryPolicy $retry = new RetryPolicy(maxAttempts: 1), ?ManualClock $clock = null): array
    {
        $generated = TestTransport::generatedClient($http, $retry);
        $api = $clock instanceof ManualClock
            ? new DealsApi($generated, new ModelMapper(), $clock, $clock)
            : new DealsApi($generated, new ModelMapper());

        return [$api, $http];
    }

    // ---- get ---------------------------------------------------------------------------------------------------

    #[Test]
    public function getFindsADealInProgressByIdWithOneRequest(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->item(self::ID, 'order-1')));

        $deal = $api->get(self::ID);

        self::assertSame([self::ID, 'order-1', false], [$deal->id, $deal->customId, $deal->isArchived()]);
        self::assertSame([['DealItem', ['id' => self::ID]]], $http->operations(sortVariables: true));
    }

    #[Test]
    public function getTreatsAnUppercaseUuidAsAnId(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->item(self::ID, 'order-1')));

        $api->get(strtoupper(self::ID));

        self::assertSame([['DealItem', ['id' => strtoupper(self::ID)]]], $http->operations(sortVariables: true));
    }

    #[Test]
    public function getFallsBackToTheArchiveByTheSameId(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), $this->archived(self::ID, 'order-1')));

        $deal = $api->get(self::ID);

        self::assertSame([self::ID, true], [$deal->id, $deal->isArchived()]);
        self::assertSame([['DealItem', ['id' => self::ID]], ['DealHistoryItem', ['id' => self::ID]]], $http->operations(sortVariables: true));
    }

    #[Test]
    public function getLooksAUuidShapedReferenceUpAsACustomIdWhenNoDealHasThatId(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), self::notFound('B'), $this->item(self::OTHER_ID, self::ID)));

        $deal = $api->get(self::ID);

        self::assertSame([self::OTHER_ID, self::ID], [$deal->id, $deal->customId]);
        self::assertSame(
            [['DealItem', ['id' => self::ID]], ['DealHistoryItem', ['id' => self::ID]], ['DealItem', ['customId' => self::ID]]],
            $http->operations(sortVariables: true),
        );
    }

    #[Test]
    public function getFindsAnArchivedDealByCustomIdAsTheLastCandidate(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), self::notFound('B'), self::notFound('C'), $this->archived(self::OTHER_ID, self::ID)));

        $deal = $api->get(self::ID);

        self::assertSame([self::OTHER_ID, true], [$deal->id, $deal->isArchived()]);
        self::assertSame(
            [['DealItem', ['id' => self::ID]], ['DealHistoryItem', ['id' => self::ID]], ['DealItem', ['customId' => self::ID]], ['DealHistoryItem', ['customId' => self::ID]]],
            $http->operations(sortVariables: true),
        );
    }

    /**
     * @return iterable<string, array{string}> references that cannot be a Uuid
     */
    public static function plainReferences(): iterable
    {
        yield 'a custom id' => ['order-42'];
        yield 'a UUID one character short' => ['0f6d2b8e-5a7c-4e1f-9b3d-6c2a8e4f1d7'];
        yield 'a UUID one character long' => ['0f6d2b8e-5a7c-4e1f-9b3d-6c2a8e4f1d700'];
        yield 'a UUID with a non-hex character' => ['0f6d2b8e-5a7c-4e1f-9b3d-6c2a8e4f1d7g'];
        yield 'a UUID without dashes' => ['0f6d2b8e5a7c4e1f9b3d6c2a8e4f1d70'];
        yield 'a UUID with a trailing newline' => ["0f6d2b8e-5a7c-4e1f-9b3d-6c2a8e4f1d70\n"];
        yield 'a UUID with a leading space' => [' 0f6d2b8e-5a7c-4e1f-9b3d-6c2a8e4f1d70'];
        yield 'a number' => ['12345'];
    }

    #[Test]
    #[DataProvider('plainReferences')]
    public function getLooksAReferenceThatIsNoUuidUpAsACustomIdOnly(string $reference): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), $this->archived(self::ID, $reference)));

        $deal = $api->get($reference);

        self::assertSame($reference, $deal->customId);
        self::assertSame([['DealItem', ['customId' => $reference]], ['DealHistoryItem', ['customId' => $reference]]], $http->operations(sortVariables: true));
    }

    #[Test]
    public function getRethrowsTheLastNotFoundWhenNothingMatches(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('first'), self::notFound('second'), self::notFound('third'), self::notFound('last')));

        try {
            $api->get(self::ID);
            self::fail('Expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('last', $e->getMessage());
        }
        self::assertCount(4, $http->requests());
    }

    #[Test]
    public function getRethrowsTheNotFoundOfTheArchiveForAPlainReference(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('item'), self::notFound('archive')));

        try {
            $api->get('order-42');
            self::fail('Expected a NotFoundException');
        } catch (NotFoundException $e) {
            self::assertStringContainsString('archive', $e->getMessage());
        }
        self::assertCount(2, $http->requests());
    }

    /**
     * @return iterable<string, array{list<ResponseInterface>, class-string<WhiteMarketException>, int}> responses, exception, requests sent
     */
    public static function failuresThatAreNotNotFound(): iterable
    {
        yield 'HTTP 500 on the first lookup' => [[FakeHttpClient::status(500)], GeneralServerException::class, 1];
        yield 'another GraphQL error on the first lookup' => [[self::graphQlError('market.deal.forbidden')], GeneralGraphQLException::class, 1];
        yield 'HTTP 500 on the archive lookup' => [[self::notFound('A'), FakeHttpClient::status(500)], GeneralServerException::class, 2];
        yield 'another GraphQL error on the second key' => [[self::notFound('A'), self::notFound('B'), self::graphQlError('market.deal.forbidden')], GeneralGraphQLException::class, 3];
    }

    /**
     * @param list<ResponseInterface> $responses
     * @param class-string<WhiteMarketException> $expected
     */
    #[Test]
    #[DataProvider('failuresThatAreNotNotFound')]
    public function getLetsAnyOtherFailureThroughAtOnce(array $responses, string $expected, int $requests): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(...$responses));

        try {
            $api->get(self::ID);
            self::fail('Expected ' . $expected);
        } catch (WhiteMarketException $e) {
            self::assertInstanceOf($expected, $e);
        }
        self::assertCount($requests, $http->requests(), 'no further lookup after a failure that is not a not-found');
    }

    #[Test]
    public function getRejectsAnEmptyReferenceBeforeAnyRequest(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $api->get('');
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
        }
    }

    // ---- statuses ----------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, list<int>}> ids requested, ids per request
     */
    public static function idCounts(): iterable
    {
        yield 'none -> no request' => [0, []];
        yield 'one' => [1, [1]];
        yield 'one below a full chunk' => [299, [299]];
        yield 'a full chunk' => [300, [300]];
        yield 'one above a full chunk' => [301, [300, 1]];
        yield 'two full chunks' => [600, [300, 300]];
        yield 'two chunks and one' => [601, [300, 300, 1]];
    }

    /**
     * @param list<int> $chunkSizes
     */
    #[Test]
    #[DataProvider('idCounts')]
    public function statusesAsksForAtMostThreeHundredIdsPerRequestAndKeepsTheRequestOrder(int $count, array $chunkSizes): void
    {
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $ids[] = 'order-' . $i;
        }
        $chunks = array_chunk($ids, DealsApi::MAX_STATUS_IDS);
        [$api, $http] = $this->api(new FakeHttpClient(...array_map(fn(array $chunk): ResponseInterface => $this->states(array_map(static fn(string $id): array => ['id' => 'deal-' . $id, 'customId' => $id], $chunk)), $chunks)));

        $states = $api->statuses($ids);

        self::assertSame($chunkSizes, array_map(\count(...), $chunks));
        self::assertSame(
            array_map(static fn(array $chunk): array => ['DealBuyerStatuses', ['ids' => $chunk]], $chunks),
            $http->operations(sortVariables: true),
        );
        self::assertSame($ids, array_map(strval(...), array_keys($states)));
        self::assertSame(array_map(static fn(string $id): string => 'deal-' . $id, $ids), array_map(static fn(DealState $s): string => $s->id, array_values($states)));
    }

    #[Test]
    public function statusesKeepsTheRequestOrderWhenTheServerAnswersInAnotherOrder(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([
            ['id' => 'id-c', 'customId' => null],
            ['id' => 'id-a', 'customId' => null],
            ['id' => 'id-b', 'customId' => null],
        ])));

        self::assertSame(['id-a', 'id-b', 'id-c'], array_keys($api->statuses(['id-a', 'id-b', 'id-c'])));
    }

    #[Test]
    public function statusesMatchesAStateToTheCustomIdThatWasRequested(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'EXCHANGED']])));

        $states = $api->statuses(['order-1']);

        $state = $states['order-1'] ?? self::fail('no state for order-1');
        self::assertSame(['order-1'], array_keys($states));
        self::assertSame([self::ID, 'order-1', DealStatus::Exchanged], [$state->id, $state->customId, $state->status]);
    }

    #[Test]
    public function statusesMatchesBothAnIdAndACustomIdInOneRequest(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->states([
            ['id' => self::ID, 'customId' => 'order-1'],
            ['id' => self::OTHER_ID, 'customId' => 'order-2'],
        ])));

        $states = $api->statuses(['order-2', self::ID]);

        self::assertSame(['order-2', self::ID], array_keys($states));
        self::assertSame(
            [self::OTHER_ID, self::ID],
            [($states['order-2'] ?? self::fail('no state'))->id, ($states[self::ID] ?? self::fail('no state'))->id],
        );
        self::assertCount(1, $http->requests());
    }

    #[Test]
    public function statusesLeavesOutWhatTheServerDoesNotKnow(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([['id' => 'id-b', 'customId' => null], ['id' => 'id-x', 'customId' => 'not-asked']])));

        self::assertSame(['id-b'], array_keys($api->statuses(['id-a', 'id-b', 'id-c'])));
    }

    #[Test]
    public function statusesSendsADuplicateOnceAndReturnsItOnce(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->states([
            ['id' => 'id-a', 'customId' => null],
            ['id' => 'id-b', 'customId' => null],
            ['id' => 'id-c', 'customId' => null],
        ])));

        $states = $api->statuses(['id-a', 'id-b', 'id-a', 'id-c', 'id-b']);

        self::assertSame([['DealBuyerStatuses', ['ids' => ['id-a', 'id-b', 'id-c']]]], $http->operations(sortVariables: true));
        self::assertSame(['id-a', 'id-b', 'id-c'], array_keys($states));
    }

    #[Test]
    public function statusesCountsDuplicatesBeforeChunking(): void
    {
        $ids = array_map(static fn(int $i): string => 'id-' . $i, range(0, 299));
        [$api, $http] = $this->api(new FakeHttpClient($this->states([])));

        $api->statuses([...$ids, ...$ids]);

        self::assertCount(1, $http->requests(), '300 distinct ids fit one request');
    }

    #[Test]
    public function statusesMatchesAnUppercaseUuidToTheLowercaseIdTheServerAnswered(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->states([['id' => self::ID, 'customId' => null]])));

        $states = $api->statuses([strtoupper(self::ID)]);

        self::assertSame([strtoupper(self::ID)], array_keys($states));
        self::assertSame(self::ID, ($states[strtoupper(self::ID)] ?? self::fail('no state'))->id);
        self::assertSame([['DealBuyerStatuses', ['ids' => [strtoupper(self::ID)]]]], $http->operations(sortVariables: true));
    }

    #[Test]
    public function statusesMatchesALowercaseUuidToTheUppercaseIdTheServerAnswered(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([['id' => strtoupper(self::ID), 'customId' => null]])));

        $states = $api->statuses([self::ID]);

        self::assertSame([self::ID], array_keys($states));
    }

    #[Test]
    public function statusesDoesNotMatchAUuidShapedCustomIdThatDiffersInCase(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([['id' => 'deal-1', 'customId' => self::OTHER_ID]])));

        self::assertSame([], $api->statuses([strtoupper(self::OTHER_ID)]));
    }

    #[Test]
    public function statusesMatchesAUuidShapedCustomIdExactly(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([['id' => 'deal-1', 'customId' => self::OTHER_ID]])));

        $states = $api->statuses([self::OTHER_ID]);

        self::assertSame('deal-1', ($states[self::OTHER_ID] ?? self::fail('no state'))->id);
    }

    #[Test]
    public function statusesKeepsTwoCustomIdsThatDifferOnlyInCaseApart(): void
    {
        $upper = strtoupper(self::OTHER_ID);
        [$api, $http] = $this->api(new FakeHttpClient($this->states([['id' => 'deal-lower', 'customId' => self::OTHER_ID], ['id' => 'deal-upper', 'customId' => $upper]])));

        $states = $api->statuses([self::OTHER_ID, $upper]);

        self::assertSame([['DealBuyerStatuses', ['ids' => [self::OTHER_ID, $upper]]]], $http->operations(sortVariables: true));
        self::assertSame(['deal-lower', 'deal-upper'], [($states[self::OTHER_ID] ?? self::fail('no state'))->id, ($states[$upper] ?? self::fail('no state'))->id]);
    }

    #[Test]
    public function statusesSendsEverySpellingOfAUuidAndReturnsEachRequestedSpelling(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->states([['id' => self::ID, 'customId' => null]])));

        $states = $api->statuses([self::ID, strtoupper(self::ID), self::ID]);

        self::assertSame([['DealBuyerStatuses', ['ids' => [self::ID, strtoupper(self::ID)]]]], $http->operations(sortVariables: true));
        self::assertSame([self::ID, strtoupper(self::ID)], array_keys($states));
        self::assertSame(self::ID, ($states[strtoupper(self::ID)] ?? self::fail('no state'))->id);
    }

    #[Test]
    public function statusesDoesNotMatchANonUuidCustomIdThatDiffersInCase(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([['id' => self::ID, 'customId' => 'Order-1']])));

        self::assertSame([], $api->statuses(['order-1']));
    }

    #[Test]
    public function statusesDoesNotMatchANonUuidIdThatDiffersInCase(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([['id' => 'Deal-A', 'customId' => null]])));

        self::assertSame([], $api->statuses(['deal-a']));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function keysThatAreAnIdOfOneDealAndTheCustomIdOfAnother(): iterable
    {
        yield 'UUID form' => [self::ID];
        yield 'free-form string' => ['deal-x'];
    }

    #[Test]
    #[DataProvider('keysThatAreAnIdOfOneDealAndTheCustomIdOfAnother')]
    public function statusesPrefersTheDealWhoseIdEqualsTheKeyOverTheOneWhoseCustomIdDoes(string $key): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([
            ['id' => 'deal-b', 'customId' => $key],
            ['id' => $key, 'customId' => null],
        ])));

        $states = $api->statuses([$key]);

        self::assertSame($key, ($states[$key] ?? self::fail('no state'))->id);
    }

    #[Test]
    public function statusesMapsTheWholeState(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([[
            'id' => 'id-a',
            'customId' => 'order-a',
            'status' => 'STEAM_ROLLBACK',
            'cancelReason' => 'STEAM_ISSUES',
            'price' => ['currency' => 'USD', 'value' => '25.000'],
            'reversePenaltyValue' => ['currency' => 'USD', 'value' => '1.250'],
            'reverseRefundValue' => ['currency' => 'USD', 'value' => '23.750'],
            'steamId' => '76561190000000002',
            'tradeId' => '9000000000000000002',
            'isTradeProtected' => true,
        ]])));

        $state = $api->statuses(['id-a'])['id-a'] ?? self::fail('no state for id-a');

        self::assertSame(
            [DealStatus::SteamRollback, '25.000 USD', '1.250 USD', '23.750 USD', '76561190000000002', '9000000000000000002', true],
            [$state->status, (string) $state->price, (string) $state->reversePenalty, (string) $state->reverseRefund, $state->steamId, $state->tradeId, $state->isTradeProtected],
        );
    }

    // ---- lists -------------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, string, class-string}> method, operation name, generated namespace
     */
    public static function lists(): iterable
    {
        yield 'listAsBuyer' => ['listAsBuyer', 'DealBuyerList', DealBuyerList::class];
        yield 'listAsSeller' => ['listAsSeller', 'DealSellerList', DealSellerList::class];
    }

    /**
     * @param list<DealStatus>|null $with
     * @param list<DealStatus>|null $without
     *
     * @return list<Deal>
     */
    private function list(DealsApi $api, string $method, ?array $with = null, ?array $without = null): array
    {
        return $method === 'listAsBuyer' ? $api->listAsBuyer($with, $without) : $api->listAsSeller($with, $without);
    }

    /**
     * @param class-string $namespace
     */
    #[Test]
    #[DataProvider('lists')]
    public function listsSendNoVariableWithoutAFilterAndMapEveryDeal(string $method, string $operation, string $namespace): void
    {
        $key = $method === 'listAsBuyer' ? 'deal_buyer_list' : 'deal_seller_list';
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => [$key => [
            SyntheticDeal::inProgress($namespace, ['customId' => 'order-1']),
            SyntheticDeal::inProgress($namespace, ['customId' => 'order-2', 'status' => 'NEED_CONFIRMATION']),
        ]]])));

        $deals = $this->list($api, $method);

        self::assertSame([[$operation, []]], $http->operations(sortVariables: true));
        self::assertSame([['order-1', DealStatus::Offered], ['order-2', DealStatus::NeedConfirmation]], array_map(static fn(Deal $d): array => [$d->customId, $d->status], $deals));
    }

    /**
     * @param class-string $namespace
     */
    #[Test]
    #[DataProvider('lists')]
    public function listsEncodeTheStatusFilters(string $method, string $operation, string $namespace): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => [$method === 'listAsBuyer' ? 'deal_buyer_list' : 'deal_seller_list' => []]])));

        $deals = $this->list($api, $method, [DealStatus::Offered, DealStatus::Exchanged], [DealStatus::Hold]);

        self::assertSame([], $deals);
        self::assertSame([[$operation, ['statusesWith' => ['OFFERED', 'EXCHANGED'], 'statusesWithout' => ['HOLD']]]], $http->operations(sortVariables: true));
    }

    /**
     * @return iterable<string, array{string, bool}> method, whether the rejected status is in the "with" list
     */
    public static function queuedFilters(): iterable
    {
        yield 'buyer, in statusesWith' => ['listAsBuyer', true];
        yield 'buyer, in statusesWithout' => ['listAsBuyer', false];
        yield 'seller, in statusesWith' => ['listAsSeller', true];
        yield 'seller, in statusesWithout' => ['listAsSeller', false];
    }

    #[Test]
    #[DataProvider('queuedFilters')]
    public function listsRejectAStatusTheSchemaDoesNotAcceptBeforeAnyRequest(string $method, bool $inWith): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $this->list($api, $method, $inWith ? [DealStatus::Offered, DealStatus::Queued] : null, $inWith ? null : [DealStatus::Queued]);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('DealStatus value QUEUED cannot be sent: the API schema does not accept it.', $e->getMessage());
            self::assertCount(0, $http->requests());
        }
    }

    // ---- history -----------------------------------------------------------------------------------------------

    #[Test]
    public function historyMapsArchivedDealsAndThePageInfo(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->historyPage(['order-1', 'order-2'], 'cursor-2', true)));

        $page = $api->history();

        self::assertSame([['DealHistory', []]], $http->operations(sortVariables: true));
        self::assertSame([['order-1', true], ['order-2', true]], array_map(static fn(Deal $d): array => [$d->customId, $d->isArchived()], $page->items));
        self::assertSame(['cursor-2', true, 7], [$page->endCursor, $page->hasNextPage, $page->totalCount]);
    }

    /**
     * The server fails `params.updatedAt` of every archived product (observed live 2026-11-01), so the override
     * `graphql/overrides/query/deal_history.graphql` does not select it: a node without it maps.
     */
    #[Test]
    public function historyMapsAnArchivedItemWithoutItsUpdateTime(): void
    {
        $node = SyntheticDeal::archived(EdgesNode::class, ['customId' => 'order-1'], self::HISTORY_CLASSES);
        $product = $node['product'] ?? null;
        self::assertIsArray($product);
        $params = $product['params'] ?? null;
        self::assertIsArray($params);
        unset($params['updatedAt']);
        $node['product'] = ['params' => $params] + $product;
        $response = FakeHttpClient::json(['data' => ['deal_history' => [
            'edges' => [['cursor' => 'c-1', 'node' => $node]],
            'pageInfo' => SyntheticResult::of(PageInfo::class, ['endCursor' => null, 'hasNextPage' => false]),
            'totalCount' => 1,
        ]]]);
        [$api] = $this->api(new FakeHttpClient($response));

        $page = $api->history();

        self::assertCount(1, $page->items);
        self::assertSame('40000000000000000002', $page->items[0]->product->item?->assetId);
    }

    #[Test]
    public function historyEncodesAFilterAndAPageRequest(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->historyPage([], null, false)));

        $api->history(new DealHistoryFilter(action: DealAction::Buy), new PageRequest(50, 'cursor-1'));

        self::assertSame(
            [['DealHistory', ['forwardPagination' => ['after' => 'cursor-1', 'first' => 50], 'search' => ['action' => 'BUY']]]],
            $http->operations(sortVariables: true),
        );
    }

    #[Test]
    public function historySendsARawInputExactly(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->historyPage([], null, false)));

        $api->history(new MarkerDealHistorySearchInput(csgoFloatFrom: '0.0700', name: 'redline'));

        self::assertSame([['DealHistory', ['search' => ['csgoFloatFrom' => '0.0700', 'name' => 'redline']]]], $http->operations(sortVariables: true));
    }

    #[Test]
    public function iterateHistoryRequestsAPageOnlyWhenThePreviousOneIsConsumed(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->historyPage(['a', 'b'], 'c2', true), $this->historyPage(['c'], 'c3', false)));

        $items = $api->iterateHistory(new DealHistoryFilter(action: DealAction::Sell), 2);
        self::assertCount(0, $http->requests(), 'created, not started');

        self::assertSame('a', $items->current()->customId);
        self::assertCount(1, $http->requests());
        $items->next();
        self::assertSame('b', $items->current()->customId);
        self::assertCount(1, $http->requests(), 'last item of page 1: page 2 is not requested yet');
        $items->next();
        self::assertSame('c', $items->current()->customId);
        self::assertCount(2, $http->requests());
        $items->next();
        self::assertFalse($items->valid());

        self::assertSame(
            [
                ['DealHistory', ['forwardPagination' => ['first' => 2], 'search' => ['action' => 'SELL']]],
                ['DealHistory', ['forwardPagination' => ['after' => 'c2', 'first' => 2], 'search' => ['action' => 'SELL']]],
            ],
            $http->operations(sortVariables: true),
        );
    }

    #[Test]
    public function iterateHistoryNumbersItemsAcrossPages(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->historyPage(['a', 'b'], 'c2', true), $this->historyPage(['c'], null, false)));

        self::assertSame(['a', 'b', 'c'], array_map(static fn(Deal $d): ?string => $d->customId, iterator_to_array($api->iterateHistory(), true)));
    }

    // ---- requestCancel -----------------------------------------------------------------------------------------

    #[Test]
    public function requestCancelSendsOneMutationWithTheIdAndMapsTheDeal(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['deal_request_cancel' => SyntheticDeal::inProgress(DealRequestCancel::class, ['status' => 'BUYER_CANCEL_REQUESTED'])]])));

        $deal = $api->requestCancel(self::ID);

        self::assertSame([['DealRequestCancel', ['id' => self::ID]]], $http->operations(sortVariables: true));
        self::assertSame([self::ID, DealStatus::BuyerCancelRequested], [$deal->id, $deal->status]);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notDealIds(): iterable
    {
        yield 'empty' => [''];
        yield 'a custom id' => ['order-42'];
        yield 'a UUID with a trailing newline' => [self::ID . "\n"];
        yield 'a UUID with a leading space' => [' ' . self::ID];
    }

    #[Test]
    #[DataProvider('notDealIds')]
    public function requestCancelRejectsAnythingButADealIdBeforeAnyRequest(string $id): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $api->requestCancel($id);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('The id must be a deal id (UUID); a customId is not accepted by deal_request_cancel.', $e->getMessage());
            self::assertCount(0, $http->requests());
        }
    }

    #[Test]
    public function requestCancelAcceptsAnUppercaseUuid(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['deal_request_cancel' => SyntheticDeal::inProgress(DealRequestCancel::class)]])));

        $api->requestCancel(strtoupper(self::ID));

        self::assertSame([['DealRequestCancel', ['id' => strtoupper(self::ID)]]], $http->operations(sortVariables: true));
    }

    // ---- reportOfferSent ---------------------------------------------------------------------------------------

    #[Test]
    public function reportOfferSentSendsAPlainReferenceAsTheCustomIdWithoutAnyLookup(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->offerSent('order-42')));

        $deal = $api->reportOfferSent('order-42', '5550001');

        self::assertSame([['DealSteamOfferSent', ['customId' => 'order-42', 'offerId' => '5550001']]], $http->operations(sortVariables: true));
        self::assertSame('order-42', $deal->customId);
    }

    #[Test]
    public function reportOfferSentResolvesAUuidShapedReferenceByQueriesThenSendsTheIdOnce(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->item(self::ID, 'order-1'), $this->offerSent('order-1')));

        $api->reportOfferSent(self::ID, '5550001');

        self::assertSame(
            [['DealItem', ['id' => self::ID]], ['DealSteamOfferSent', ['id' => self::ID, 'offerId' => '5550001']]],
            $http->operations(sortVariables: true),
        );
    }

    #[Test]
    public function reportOfferSentSendsTheIdFoundForAUuidShapedCustomId(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), self::notFound('B'), $this->item(self::OTHER_ID, self::ID), $this->offerSent(self::ID)));

        $api->reportOfferSent(self::ID, '5550001');

        self::assertSame(
            [
                ['DealItem', ['id' => self::ID]],
                ['DealHistoryItem', ['id' => self::ID]],
                ['DealItem', ['customId' => self::ID]],
                ['DealSteamOfferSent', ['id' => self::OTHER_ID, 'offerId' => '5550001']],
            ],
            $http->operations(sortVariables: true),
        );
    }

    /**
     * @return iterable<string, array{string, string, ?string}> reference, the id and customId of the deal the server returned
     */
    public static function wrongDeals(): iterable
    {
        yield 'another id, no customId' => [self::ID, self::OTHER_ID, null];
        yield 'another id and another customId' => [self::ID, self::OTHER_ID, 'order-1'];
    }

    #[Test]
    #[DataProvider('wrongDeals')]
    public function aMutationIsNotSentWhenTheServerReturnsAnotherDealThanTheReference(string $reference, string $id, ?string $customId): void
    {
        foreach (['reportOfferSent', 'cancelSendingOffer'] as $call) {
            [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['deal_item' => SyntheticDeal::inProgress(DealItem::class, ['id' => $id, 'customId' => $customId])]])));

            try {
                $call === 'reportOfferSent' ? $api->reportOfferSent($reference, '5550001') : $api->cancelSendingOffer($reference, OfferCancelReason::Unknown, 'x');
                self::fail('Expected a DecodeException');
            } catch (DecodeException $e) {
                self::assertSame('deal_item.id', $e->path);
                self::assertStringContainsString('the server returned a different deal than the one requested', $e->getMessage());
            }
            self::assertSame([['DealItem', ['id' => $reference]]], $http->operations(sortVariables: true), $call . ': no mutation sent');
        }
    }

    #[Test]
    public function aMutationAcceptsADealWhoseIdDiffersFromTheUppercaseReferenceOnlyInCase(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->item(self::ID, 'order-1'), $this->offerSent('order-1')));

        $api->reportOfferSent(strtoupper(self::ID), '5550001');

        self::assertSame(
            [['DealItem', ['id' => strtoupper(self::ID)]], ['DealSteamOfferSent', ['id' => self::ID, 'offerId' => '5550001']]],
            $http->operations(sortVariables: true),
        );
    }

    #[Test]
    public function aMutationAcceptsADealWhoseUppercaseIdDiffersFromTheLowercaseReferenceOnlyInCase(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->item(strtoupper(self::ID), 'order-1'), $this->offerSent('order-1')));

        $api->reportOfferSent(self::ID, '5550001');

        self::assertSame(
            [['DealItem', ['id' => self::ID]], ['DealSteamOfferSent', ['id' => strtoupper(self::ID), 'offerId' => '5550001']]],
            $http->operations(sortVariables: true),
        );
    }

    #[Test]
    public function aMutationIsNotSentWhenTheDealsCustomIdDiffersFromTheReferenceInCase(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), self::notFound('B'), $this->item(self::OTHER_ID, self::ID)));

        try {
            $api->reportOfferSent(strtoupper(self::ID), '5550001');
            self::fail('expected DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('deal_item.id', $e->path);
        }
        self::assertNotContains('DealSteamOfferSent', array_column($http->operations(sortVariables: true), 0));
    }

    #[Test]
    public function aMutationAcceptsADealWhoseCustomIdEqualsTheReferenceExactly(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), self::notFound('B'), $this->item(self::OTHER_ID, self::ID), $this->offerSent(self::ID)));

        $api->reportOfferSent(self::ID, '5550001');

        self::assertSame(['DealSteamOfferSent', ['id' => self::OTHER_ID, 'offerId' => '5550001']], $http->operations(sortVariables: true)[3] ?? null);
    }

    #[Test]
    public function reportOfferSentSendsNothingWhenTheDealIsNotFound(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), self::notFound('B'), self::notFound('C'), self::notFound('D')));

        $this->expectException(NotFoundException::class);
        try {
            $api->reportOfferSent(self::ID, '5550001');
        } finally {
            self::assertNotContains('DealSteamOfferSent', array_column($http->operations(sortVariables: true), 0));
        }
    }

    // ---- cancelSendingOffer ------------------------------------------------------------------------------------

    #[Test]
    public function cancelSendingOfferSendsOneMutationAndReturnsTheArchivedDeal(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->cancelledOffer('order-42')));

        $deal = $api->cancelSendingOffer('order-42', OfferCancelReason::SteamIssues15, 'Steam is down');

        self::assertSame(
            [['DealCancelSendingOffer', ['comment' => 'Steam is down', 'customId' => 'order-42', 'reason' => 'STEAM_ISSUES_15']]],
            $http->operations(sortVariables: true),
        );
        self::assertSame(['order-42', true], [$deal->customId, $deal->isArchived()]);
    }

    #[Test]
    public function cancelSendingOfferResolvesAUuidShapedReferenceThenSendsTheIdOnce(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(self::notFound('A'), self::notFound('B'), $this->item(self::OTHER_ID, self::ID), $this->cancelledOffer(self::ID)));

        $api->cancelSendingOffer(self::ID, OfferCancelReason::SkinUnavailable, 'gone');

        self::assertSame(
            [
                ['DealItem', ['id' => self::ID]],
                ['DealHistoryItem', ['id' => self::ID]],
                ['DealItem', ['customId' => self::ID]],
                ['DealCancelSendingOffer', ['comment' => 'gone', 'id' => self::OTHER_ID, 'reason' => 'SKIN_UNAVAILABLE']],
            ],
            $http->operations(sortVariables: true),
        );
    }

    /**
     * @return iterable<string, array{OfferCancelReason}>
     */
    public static function reasons(): iterable
    {
        foreach (OfferCancelReason::cases() as $reason) {
            yield $reason->value => [$reason];
        }
    }

    #[Test]
    #[DataProvider('reasons')]
    public function cancelSendingOfferSendsTheReasonByItsWireValue(OfferCancelReason $reason): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->cancelledOffer('order-42')));

        $api->cancelSendingOffer('order-42', $reason, 'x');

        self::assertSame($reason->value, $http->operations(sortVariables: true)[0][1]['reason'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedComments(): iterable
    {
        yield 'empty' => [''];
        yield 'one letter' => ['a'];
        yield 'every allowed kind of character' => ['Az09 _-'];
        yield 'exactly 255 characters' => [str_repeat('a', 255)];
        yield 'only spaces' => ['   '];
    }

    #[Test]
    #[DataProvider('acceptedComments')]
    public function cancelSendingOfferAcceptsACommentWithinTheRule(string $comment): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->cancelledOffer('order-42')));

        $api->cancelSendingOffer('order-42', OfferCancelReason::Unknown, $comment);

        self::assertSame($comment, $http->operations(sortVariables: true)[0][1]['comment'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedComments(): iterable
    {
        yield '256 characters' => [str_repeat('a', 256)];
        yield 'far too long' => [str_repeat('a', 1000)];
        yield 'a non-ASCII letter' => ['caf' . "\u{e9}"];
        yield 'an exclamation mark' => ['no!'];
        yield 'a dot' => ['a.b'];
        yield 'a newline in the middle' => ["line\nbreak"];
        yield 'a trailing newline' => ["ok\n"];
        yield 'a tab' => ["a\tb"];
        yield 'a null byte' => ["a\0b"];
        yield 'a quote' => ['say "hi"'];
    }

    #[Test]
    #[DataProvider('rejectedComments')]
    public function cancelSendingOfferRejectsACommentOutsideTheRuleBeforeAnyRequestEvenForAUuidReference(string $comment): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $api->cancelSendingOffer(self::ID, OfferCancelReason::Unknown, $comment);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString($comment, $e->getMessage(), 'the comment is not echoed');
            self::assertCount(0, $http->requests());
        }
    }

    #[Test]
    public function cancelSendingOfferNamesTheViolatedRule(): void
    {
        [$api] = $this->api(new FakeHttpClient());

        $messages = [];
        foreach ([str_repeat('a', 256), 'no!'] as $comment) {
            try {
                $api->cancelSendingOffer('order-42', OfferCancelReason::Unknown, $comment);
            } catch (\InvalidArgumentException $e) {
                $messages[] = $e->getMessage();
            }
        }

        self::assertSame(
            ['The cancel comment is longer than 255 characters.', 'The cancel comment may contain only the characters a-z, A-Z, 0-9, space, "_" and "-".'],
            $messages,
        );
    }

    // ---- mutations: once, never retried, empty reference --------------------------------------------------------

    /**
     * @return iterable<string, array{string}>
     */
    public static function mutations(): iterable
    {
        yield 'requestCancel' => ['requestCancel'];
        yield 'reportOfferSent by customId' => ['reportOfferSent'];
        yield 'cancelSendingOffer by customId' => ['cancelSendingOffer'];
    }

    private function mutate(DealsApi $api, string $call): Deal
    {
        return match ($call) {
            'requestCancel' => $api->requestCancel(self::ID),
            'reportOfferSent' => $api->reportOfferSent('order-42', '5550001'),
            'cancelSendingOffer' => $api->cancelSendingOffer('order-42', OfferCancelReason::Unknown, 'x'),
            default => throw new \LogicException('Unknown call ' . $call),
        };
    }

    #[Test]
    #[DataProvider('mutations')]
    public function aMutationIsSentOnceEvenWhenTheServerAnswers503AndTheRetryPolicyIsTheDefault(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::status(503), FakeHttpClient::status(503)), new RetryPolicy());

        try {
            $this->mutate($api, $call);
            self::fail('Expected a ServiceUnavailableException');
        } catch (ServiceUnavailableException) {
            self::assertCount(1, $http->requests());
        }
    }

    #[Test]
    #[DataProvider('mutations')]
    public function aMutationIsSentOnceWhenTheNetworkFails(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::networkError(), FakeHttpClient::networkError()), new RetryPolicy());

        try {
            $this->mutate($api, $call);
            self::fail('Expected a TransportException');
        } catch (WhiteMarketException) {
            self::assertCount(1, $http->requests());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function referenceMutations(): iterable
    {
        yield 'reportOfferSent' => ['reportOfferSent'];
        yield 'cancelSendingOffer' => ['cancelSendingOffer'];
    }

    #[Test]
    #[DataProvider('referenceMutations')]
    public function aMutationWithAnEmptyReferenceFailsBeforeAnyRequest(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $call === 'reportOfferSent' ? $api->reportOfferSent('', '5550001') : $api->cancelSendingOffer('', OfferCancelReason::Unknown, 'x');
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
        }
    }

    // ---- hold balances -----------------------------------------------------------------------------------------

    #[Test]
    public function holdBalanceSendsTheCurrencyAndMapsTheAmount(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['deal_balance_hold' => ['currency' => 'USD', 'value' => '1234.560']]])));

        $money = $api->holdBalance(Currency::Usd);

        self::assertSame([['DealBalanceHold', ['currency' => 'USD']]], $http->operations(sortVariables: true));
        self::assertSame('1234.560 USD', (string) $money);
    }

    #[Test]
    public function holdBalancesMapsEveryCurrency(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['deal_balances_hold' => [
            ['currency' => 'USD', 'value' => '1.50'],
            ['currency' => 'EUR', 'value' => '0'],
        ]]])));

        $held = $api->holdBalances();

        self::assertSame([['DealBalancesHold', []]], $http->operations(sortVariables: true));
        self::assertSame(['1.50 USD', '0 EUR'], array_map(strval(...), $held));
        self::assertInstanceOf(UnknownEnumValue::class, ($held[1] ?? self::fail('no second balance'))->currency);
    }

    #[Test]
    public function holdBalancesIsEmptyWhenNothingIsHeld(): void
    {
        [$api] = $this->api(new FakeHttpClient(FakeHttpClient::json(['data' => ['deal_balances_hold' => []]])));

        self::assertSame([], $api->holdBalances());
    }

    // ---- waitFor -----------------------------------------------------------------------------------------------

    #[Test]
    public function waitForReturnsTheStateOfTheFirstPollThatMatchesWithoutSleeping(): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient($this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'OFFERED']])), clock: $clock);

        $state = $api->waitFor('order-1', Until::offered());

        self::assertSame([self::ID, DealStatus::Offered], [$state->id, $state->status]);
        self::assertSame([['DealBuyerStatuses', ['ids' => ['order-1']]]], $http->operations(sortVariables: true));
        self::assertSame([], $clock->delays());
    }

    #[Test]
    public function waitForPollsEveryFiveSecondsByDefaultUntilTheStateMatches(): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient(
            $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'INITIATED']]),
            $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'SELLER_SENDING_STEAM_OFFER']]),
            $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'EXCHANGED']]),
        ), clock: $clock);

        $state = $api->waitFor('order-1', Until::exchangedOrCompleted());

        self::assertSame(DealStatus::Exchanged, $state->status);
        self::assertCount(3, $http->requests());
        self::assertSame([5.0, 5.0], $clock->delays());
    }

    #[Test]
    public function waitForUsesTheGivenPollInterval(): void
    {
        $clock = new ManualClock();
        [$api] = $this->api(new FakeHttpClient(
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'INITIATED']]),
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'COMPLETED']]),
        ), clock: $clock);

        $api->waitFor(self::ID, Until::final(), pollInterval: 0.25);

        self::assertSame([0.25], $clock->delays());
    }

    #[Test]
    public function waitForPassesTheStateToTheCallerCondition(): void
    {
        $seen = [];
        [$api] = $this->api(new FakeHttpClient(
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'INITIATED']]),
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'HOLD']]),
        ), clock: new ManualClock());

        $state = $api->waitFor(self::ID, static function (DealState $state) use (&$seen): bool {
            $seen[] = $state->status;

            return $state->status === DealStatus::Hold;
        });

        self::assertSame([DealStatus::Initiated, DealStatus::Hold], $seen);
        self::assertSame(DealStatus::Hold, $state->status);
    }

    #[Test]
    public function waitForGivesUpWhenTheTimeoutHasElapsedAndCarriesTheLastState(): void
    {
        $clock = new ManualClock();
        $initiated = fn(): ResponseInterface => $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'INITIATED']]);
        [$api, $http] = $this->api(new FakeHttpClient($initiated(), $initiated(), $initiated(), $initiated()), clock: $clock);

        try {
            $api->waitFor('order-1', Until::offered(), timeout: 12.0);
            self::fail('Expected a DealWaitTimeoutException');
        } catch (DealWaitTimeoutException $e) {
            self::assertSame(['order-1', 12.0], [$e->idOrCustomId, $e->timeout]);
            self::assertSame([self::ID, DealStatus::Initiated], [$e->lastState?->id, $e->lastState->status ?? null]);
        }
        // Polls at 0 s, 5 s, 10 s and 12 s (the last sleep is capped at the 2 s that remain): the fourth finds 12 >= 12.
        self::assertCount(4, $http->requests());
        self::assertSame([5.0, 5.0, 2.0], $clock->delays());
    }

    #[Test]
    public function waitForNeverSleepsPastTheTimeoutAndChecksOnceMoreAtTheDeadline(): void
    {
        $clock = new ManualClock();
        $initiated = fn(): ResponseInterface => $this->states([['id' => self::ID, 'customId' => null, 'status' => 'INITIATED']]);
        [$api, $http] = $this->api(new FakeHttpClient($initiated(), $initiated(), $initiated()), clock: $clock);

        try {
            $api->waitFor(self::ID, Until::offered(), timeout: 1.0, pollInterval: 3600.0);
            self::fail('Expected a DealWaitTimeoutException');
        } catch (DealWaitTimeoutException) {
            self::assertSame([1.0], $clock->delays());
            self::assertCount(2, $http->requests());
        }
    }

    #[Test]
    public function waitForReturnsAMatchFoundByThePollAtTheDeadline(): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient(
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'INITIATED']]),
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'OFFERED']]),
        ), clock: $clock);

        $state = $api->waitFor(self::ID, Until::offered(), timeout: 1.0, pollInterval: 3600.0);

        self::assertSame(DealStatus::Offered, $state->status);
        self::assertSame([1.0], $clock->delays());
        self::assertCount(2, $http->requests());
    }

    #[Test]
    public function waitForSleepsTheFullIntervalWithoutATimeout(): void
    {
        $clock = new ManualClock();
        [$api] = $this->api(new FakeHttpClient(
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'INITIATED']]),
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'COMPLETED']]),
        ), clock: $clock);

        $api->waitFor(self::ID, Until::final(), pollInterval: 3600.0);

        self::assertSame([3600.0], $clock->delays());
    }

    #[Test]
    public function waitForStopsExactlyWhenTheElapsedTimeEqualsTheTimeout(): void
    {
        $clock = new ManualClock();
        $initiated = fn(): ResponseInterface => $this->states([['id' => self::ID, 'customId' => null, 'status' => 'INITIATED']]);
        [$api, $http] = $this->api(new FakeHttpClient($initiated(), $initiated(), $initiated(), $initiated()), clock: $clock);

        $this->expectException(DealWaitTimeoutException::class);
        try {
            $api->waitFor(self::ID, Until::offered(), timeout: 10.0);
        } finally {
            self::assertCount(3, $http->requests(), 'polls at 0 s, 5 s and 10 s; at 10 s the timeout has elapsed');
            self::assertSame([5.0, 5.0], $clock->delays());
        }
    }

    #[Test]
    public function waitForWithTimeoutZeroPollsExactlyOnce(): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient($this->states([['id' => self::ID, 'customId' => null, 'status' => 'INITIATED']])), clock: $clock);

        try {
            $api->waitFor(self::ID, Until::offered(), timeout: 0.0);
            self::fail('Expected a DealWaitTimeoutException');
        } catch (DealWaitTimeoutException $e) {
            self::assertSame(DealStatus::Initiated, $e->lastState->status ?? null);
        }
        self::assertCount(1, $http->requests());
        self::assertSame([], $clock->delays());
    }

    #[Test]
    public function waitForWithTimeoutZeroStillReturnsAMatchOfTheFirstPoll(): void
    {
        [$api] = $this->api(new FakeHttpClient($this->states([['id' => self::ID, 'customId' => null, 'status' => 'COMPLETED']])), clock: new ManualClock());

        self::assertSame(DealStatus::Completed, $api->waitFor(self::ID, Until::final(), timeout: 0.0)->status);
    }

    #[Test]
    public function waitForKeepsPollingWhileTheDealIsNotVisibleYet(): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient(
            $this->states([]),
            self::notFound('item'),
            self::notFound('history'),
            $this->itemWithStatus('order-1', 'OFFERED'),
        ), clock: $clock);

        $state = $api->waitFor('order-1', Until::offered());

        self::assertSame(DealStatus::Offered, $state->status);
        self::assertCount(4, $http->requests());
        self::assertSame([5.0], $clock->delays());
    }

    #[Test]
    public function waitForKeepsPollingOnAStatusItDoesNotKnow(): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient(
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'SOMETHING_NEW']]),
            $this->states([['id' => self::ID, 'customId' => null, 'status' => 'COMPLETED']]),
        ), clock: $clock);

        $state = $api->waitFor(self::ID, Until::final());

        self::assertSame(DealStatus::Completed, $state->status);
        self::assertCount(2, $http->requests());
    }

    #[Test]
    public function waitForLetsAFailedPollThrough(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::status(500)), clock: new ManualClock());

        $this->expectException(GeneralServerException::class);
        try {
            $api->waitFor(self::ID, Until::final());
        } finally {
            self::assertCount(1, $http->requests());
        }
    }

    #[Test]
    public function waitForReadsASellerDealWithGetWhenTheStatusesDoNotKnowIt(): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient(
            $this->states([]),
            $this->itemWithStatus('order-1', 'OFFERED'),
        ), clock: $clock);

        $state = $api->waitFor('order-1', Until::offered());

        self::assertSame([self::ID, 'order-1', DealStatus::Offered], [$state->id, $state->customId, $state->status]);
        self::assertSame(
            [['DealBuyerStatuses', ['ids' => ['order-1']]], ['DealItem', ['customId' => 'order-1']]],
            $http->operations(sortVariables: true),
        );
        self::assertSame([], $clock->delays());
    }

    #[Test]
    public function waitForPollsOnlyDealItemAfterTheFirstFallback(): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient(
            $this->states([]),
            $this->itemWithStatus('order-1', 'INITIATED'),
            $this->itemWithStatus('order-1', 'SELLER_NEED_CONFIRMATION'),
            $this->itemWithStatus('order-1', 'OFFERED'),
        ), clock: $clock);

        $state = $api->waitFor('order-1', Until::offered());

        self::assertSame(DealStatus::Offered, $state->status);
        self::assertSame(
            [
                ['DealBuyerStatuses', ['ids' => ['order-1']]],
                ['DealItem', ['customId' => 'order-1']],
                ['DealItem', ['customId' => 'order-1']],
                ['DealItem', ['customId' => 'order-1']],
            ],
            $http->operations(sortVariables: true),
        );
        self::assertSame([5.0, 5.0], $clock->delays());
    }

    #[Test]
    public function waitForKeepsUsingOnlyStatusesForABuyerDeal(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(
            $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'INITIATED']]),
            $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'OFFERED']]),
        ), clock: new ManualClock());

        $api->waitFor('order-1', Until::offered());

        self::assertSame(
            [['DealBuyerStatuses', ['ids' => ['order-1']]], ['DealBuyerStatuses', ['ids' => ['order-1']]]],
            $http->operations(sortVariables: true),
        );
    }

    #[Test]
    public function waitForTimesOutWhenNeitherTheStatusesNorTheDealLookupKnowTheDeal(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(
            $this->states([]),
            self::notFound('item 1'),
            self::notFound('history 1'),
            self::notFound('item 2'),
            self::notFound('history 2'),
        ), clock: new ManualClock());

        try {
            $api->waitFor('order-1', Until::offered(), timeout: 5.0);
            self::fail('Expected a DealWaitTimeoutException');
        } catch (DealWaitTimeoutException $e) {
            self::assertNull($e->lastState);
        }
        self::assertSame(
            [
                ['DealBuyerStatuses', ['ids' => ['order-1']]],
                ['DealItem', ['customId' => 'order-1']],
                ['DealHistoryItem', ['customId' => 'order-1']],
                ['DealItem', ['customId' => 'order-1']],
                ['DealHistoryItem', ['customId' => 'order-1']],
            ],
            $http->operations(sortVariables: true),
        );
    }

    /**
     * @return iterable<string, array{string, ?float, float}> reference, timeout, poll interval
     */
    public static function invalidWaits(): iterable
    {
        yield 'empty reference' => ['', null, 5.0];
        yield 'poll interval 0' => ['order-1', null, 0.0];
        yield 'negative poll interval' => ['order-1', null, -1.0];
        yield 'infinite poll interval' => ['order-1', null, INF];
        yield 'NaN poll interval' => ['order-1', null, NAN];
        yield 'negative timeout' => ['order-1', -1.0, 5.0];
        yield 'infinite timeout' => ['order-1', INF, 5.0];
        yield 'NaN timeout' => ['order-1', NAN, 5.0];
    }

    #[Test]
    #[DataProvider('invalidWaits')]
    public function waitForRejectsInvalidArgumentsBeforeAnyRequest(string $reference, ?float $timeout, float $pollInterval): void
    {
        $clock = new ManualClock();
        [$api, $http] = $this->api(new FakeHttpClient(), clock: $clock);

        try {
            $api->waitFor($reference, Until::final(), $timeout, $pollInterval);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
            self::assertSame([], $clock->delays());
        }
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    private static function notFound(string $marker): ResponseInterface
    {
        return FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Deal not found ' . $marker, 'extensions' => ['category' => 'market.deal.not_found']]]]);
    }

    private static function graphQlError(string $category): ResponseInterface
    {
        return FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Rejected', 'extensions' => ['category' => $category]]]]);
    }

    private function item(string $id, string $customId): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['deal_item' => SyntheticDeal::inProgress(DealItem::class, ['id' => $id, 'customId' => $customId])]]);
    }

    private function itemWithStatus(string $customId, string $status): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['deal_item' => SyntheticDeal::inProgress(DealItem::class, ['id' => self::ID, 'customId' => $customId, 'status' => $status])]]);
    }

    private function archived(string $id, string $customId): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['deal_history_item' => SyntheticDeal::archived(DealHistoryItem::class, ['id' => $id, 'customId' => $customId])]]);
    }

    private function offerSent(string $customId): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['deal_steam_offer_sent' => SyntheticDeal::inProgress(DealSteamOfferSent::class, ['customId' => $customId, 'status' => 'OFFERED'])]]);
    }

    private function cancelledOffer(string $customId): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['deal_cancel_sending_offer' => SyntheticDeal::archived(DealCancelSendingOffer::class, ['customId' => $customId, 'status' => 'SELLER_CANCELLED'])]]);
    }

    // ---- waitFor with live -------------------------------------------------------------------------------------

    /**
     * @param list<string|\Throwable|array{float, string|\Throwable}|null> $reads
     *
     * @return array{Live, ScriptedConnection}
     */
    private function live(ManualClock $clock, array $reads): array
    {
        $connection = new ScriptedConnection($clock, [new ConnectInfo(25, true, 86400, [])], $reads);
        $live = new Live(new LiveSession(
            new ScriptedTokens(),
            static fn(): ScriptedConnection => $connection,
            new LiveOptions(),
            new MemoryLogger(),
            $clock,
            $clock,
            $handlers = new LiveHandlers(),
            new FixedRandomSource(1.0),
        ), $handlers);

        return [$live, $connection];
    }

    private function dealEvent(string $dealId): string
    {
        $message = json_encode(LiveFixture::with('deal_completed.json', ['deal_id' => $dealId]), \JSON_THROW_ON_ERROR);

        return json_encode(['push' => ['channel' => '#42', 'pub' => ['data' => ['message' => $message]]]], \JSON_THROW_ON_ERROR);
    }

    private function initiated(): ResponseInterface
    {
        return $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'INITIATED']]);
    }

    #[Test]
    public function waitForWithLiveWakesEarlyOnADealEventOfTheWaitedDeal(): void
    {
        $clock = new ManualClock();
        $start = (float) $clock->now()->format('U.u');
        [$live, $connection] = $this->live($clock, [[2.0, $this->dealEvent(self::ID)]]);
        [$api, $http] = $this->api(new FakeHttpClient($this->initiated(), $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'OFFERED']])), clock: $clock);

        $state = $api->waitFor('order-1', Until::offered(), pollInterval: 20.0, live: $live);

        self::assertSame(DealStatus::Offered, $state->status);
        self::assertCount(2, $http->requests());
        self::assertSame([], $clock->delays());
        self::assertEqualsWithDelta(20.0, $connection->deadlineAt(0) - $start, 0.001);
    }

    #[Test]
    public function waitForWithLiveMatchesTheDealIdCaseInsensitively(): void
    {
        $clock = new ManualClock();
        [$live] = $this->live($clock, [[1.0, $this->dealEvent(strtoupper(self::ID))]]);
        [$api, $http] = $this->api(new FakeHttpClient($this->initiated(), $this->states([['id' => self::ID, 'customId' => null, 'status' => 'OFFERED']])), clock: $clock);

        $api->waitFor(self::ID, Until::offered(), timeout: 30.0, pollInterval: 20.0, live: $live);

        self::assertCount(2, $http->requests());
    }

    #[Test]
    public function waitForWithLiveIgnoresDealEventsOfOtherDealsAndProductEvents(): void
    {
        $clock = new ManualClock();
        $product = LiveFixture::raw('ws_product_added.json');
        [$live] = $this->live($clock, [[1.0, $product], [1.0, $this->dealEvent(self::OTHER_ID)], [1.0, $product], null]);
        [$api, $http] = $this->api(new FakeHttpClient($this->initiated(), $this->initiated()), clock: $clock);

        try {
            $api->waitFor(self::ID, Until::offered(), timeout: 5.0, pollInterval: 5.0, live: $live);
            self::fail('Expected a DealWaitTimeoutException');
        } catch (DealWaitTimeoutException) {
            self::assertCount(2, $http->requests());
            self::assertSame([], $clock->delays());
        }
    }

    #[Test]
    public function waitForWithLivePollsOnlyAfterTheFullIntervalWhenNoEventMatches(): void
    {
        $clock = new ManualClock();
        $start = (float) $clock->now()->format('U.u');
        [$live] = $this->live($clock, [[3.0, LiveFixture::raw('ws_product_added.json')], null]);
        [$api, $http] = $this->api(new FakeHttpClient($this->initiated(), $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'OFFERED']])), clock: $clock);

        $api->waitFor(self::ID, Until::offered(), pollInterval: 10.0, live: $live);

        self::assertCount(2, $http->requests());
        self::assertEqualsWithDelta(10.0, (float) $clock->now()->format('U.u') - $start, 0.001);
    }

    #[Test]
    public function waitForWithLiveNeverPollsLongerThanTheRemainingIntervalOrDeadline(): void
    {
        $clock = new ManualClock();
        $product = LiveFixture::raw('ws_product_added.json');
        [$live, $connection] = $this->live($clock, [[2.0, $product], null, [1.0, $product], null]);
        [$api] = $this->api(new FakeHttpClient($this->initiated(), $this->initiated(), $this->initiated()), clock: $clock);
        $start = (float) $clock->now()->format('U.u');

        try {
            $api->waitFor(self::ID, Until::offered(), timeout: 8.0, pollInterval: 5.0, live: $live);
            self::fail('Expected a DealWaitTimeoutException');
        } catch (DealWaitTimeoutException) {
            // interval 1: event at 2s, then idle to 5s. interval 2: min(5, 3s left) = 3s, event at 6s, idle to 8s.
            self::assertEqualsWithDelta(5.0, $connection->deadlineAt(1) - $start, 0.001);
            self::assertEqualsWithDelta(8.0, $connection->deadlineAt(3) - $start, 0.001);
        }
    }

    #[Test]
    public function waitForWithLiveTimesOutAtTheDeadlineLikeWithoutLive(): void
    {
        $clock = new ManualClock();
        [$live] = $this->live($clock, [null, null]);
        [$api, $http] = $this->api(new FakeHttpClient($this->initiated(), $this->initiated()), clock: $clock);

        try {
            $api->waitFor(self::ID, Until::offered(), timeout: 1.0, pollInterval: 3600.0, live: $live);
            self::fail('Expected a DealWaitTimeoutException');
        } catch (DealWaitTimeoutException $e) {
            self::assertSame(1.0, $e->timeout);
            self::assertSame(DealStatus::Initiated, $e->lastState?->status);
            self::assertCount(2, $http->requests());
        }
    }

    #[Test]
    public function waitForWithLiveLetsAHandlerExceptionThrough(): void
    {
        $clock = new ManualClock();
        [$live] = $this->live($clock, [[1.0, $this->dealEvent(self::OTHER_ID)]]);
        $live->on(DealEvent::class, static function (): never {
            throw new \Error('handler failed');
        });
        [$api] = $this->api(new FakeHttpClient($this->initiated()), clock: $clock);

        $this->expectException(\Error::class);
        $this->expectExceptionMessage('handler failed');
        $api->waitFor(self::ID, Until::offered(), live: $live);
    }

    #[Test]
    public function waitForWithAClosedLiveStillSleepsTheInterval(): void
    {
        $clock = new ManualClock();
        [$live] = $this->live($clock, []);
        $live->close();
        [$api] = $this->api(new FakeHttpClient($this->initiated(), $this->states([['id' => self::ID, 'customId' => 'order-1', 'status' => 'OFFERED']])), clock: $clock);

        $api->waitFor(self::ID, Until::offered(), pollInterval: 4.0, live: $live);

        self::assertSame([4.0], $clock->delays());
    }

    /**
     * @param list<array<string, mixed>> $rows overrides of `deal_buyer_statuses` entries; `status` defaults to `OFFERED`
     */
    private function states(array $rows): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['deal_buyer_statuses' => array_map(
            static fn(array $row): array => $row + ['status' => 'OFFERED', 'price' => ['currency' => 'USD', 'value' => '25.000']] + SyntheticResult::of(StatusResult::class),
            $rows,
        )]]);
    }

    /**
     * @param list<string> $customIds
     */
    private function historyPage(array $customIds, ?string $endCursor, bool $hasNextPage): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['deal_history' => [
            'edges' => array_map(
                static fn(string $customId): array => ['cursor' => 'c-' . $customId, 'node' => SyntheticDeal::archived(EdgesNode::class, ['customId' => $customId], self::HISTORY_CLASSES)],
                $customIds,
            ),
            'pageInfo' => SyntheticResult::of(PageInfo::class, ['endCursor' => $endCursor, 'hasNextPage' => $hasNextPage]),
            'totalCount' => 7,
        ]]]);
    }
}
