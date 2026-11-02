<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Wallet;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\BadRequestException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\MutationOutcomeUnknownException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Fragment\PersonPublicFields;
use WhiteMarket\Sdk\Generated\Input\WalletTransactionSearchInput;
use WhiteMarket\Sdk\Generated\Operation\Mutation\WalletCreditGet\Result as CreditGetResult;
use WhiteMarket\Sdk\Generated\Operation\Mutation\WalletTransferSend\Result as TransferSendResult;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletDeposits\Result as DepositResult;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransactions\EdgesNode as TransactionNode;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransactions\PageInfo as TransactionsPageInfo;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransfers\EdgesNode as TransferNode;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransfers\PageInfo as TransfersPageInfo;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletWithdrawals\Result as WithdrawalResult;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DepositStatus;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\PaymentProvider;
use WhiteMarket\Sdk\Models\TransactionReason;
use WhiteMarket\Sdk\Models\TransactionSortField;
use WhiteMarket\Sdk\Models\TransactionStatus;
use WhiteMarket\Sdk\Models\TransferStatus;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Models\WithdrawalStatus;
use WhiteMarket\Sdk\Tests\Support\DebugOutput;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;
use WhiteMarket\Sdk\Tests\Support\TestMoney;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\Wallet\Credit;
use WhiteMarket\Sdk\Wallet\Debt;
use WhiteMarket\Sdk\Wallet\WalletApi;
use WhiteMarket\Sdk\Wallet\WalletTransaction;
use WhiteMarket\Sdk\Wallet\WalletTransactionFilter;
use WhiteMarket\Sdk\Wallet\WalletTransactionSort;

#[CoversClass(WalletApi::class)]
#[CoversClass(Credit::class)]
#[CoversClass(Debt::class)]
final class WalletApiTest extends TestCase
{
    private function api(FakeHttpClient $http, RetryPolicy $retry = new RetryPolicy()): WalletApi
    {
        return new WalletApi(TestTransport::generatedClient($http, $retry), new ModelMapper());
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function ok(array $data): ResponseInterface
    {
        return FakeHttpClient::json(['data' => $data]);
    }

    /**
     * @return array{currency: string, value: string}
     */
    private static function money(string $value, string $currency = 'USD'): array
    {
        return ['currency' => $currency, 'value' => $value];
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function person(string $id, array $overrides = []): array
    {
        return SyntheticResult::of(PersonPublicFields::class, $overrides + ['id' => $id, 'steamId' => '76561190000000001', 'steamName' => 'Synthetic', 'steamLevel' => 7, 'deliveryPromise' => 'HOUR1']);
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    private static function transfersPage(array $ids, ?string $endCursor, bool $hasNextPage): array
    {
        return ['wallet_transfers' => [
            'edges' => array_map(
                static fn(string $id): array => ['cursor' => 'c-' . $id, 'node' => SyntheticResult::of(TransferNode::class, ['id' => $id, 'amount' => self::money('1.00')])],
                $ids,
            ),
            'pageInfo' => SyntheticResult::of(TransfersPageInfo::class, ['endCursor' => $endCursor, 'hasNextPage' => $hasNextPage]),
            'totalCount' => 9,
        ]];
    }

    /**
     * @param list<string> $ids
     *
     * @return array<string, mixed>
     */
    private static function transactionsPage(array $ids, ?string $endCursor, bool $hasNextPage): array
    {
        return ['wallet_transactions' => [
            'edges' => array_map(
                static fn(string $id): array => ['cursor' => 'c-' . $id, 'node' => SyntheticResult::of(TransactionNode::class, ['id' => $id, 'amount' => self::money('1.00')])],
                $ids,
            ),
            'pageInfo' => SyntheticResult::of(TransactionsPageInfo::class, ['endCursor' => $endCursor, 'hasNextPage' => $hasNextPage]),
            'totalCount' => 9,
        ]];
    }

    /**
     * @template T
     *
     * @param list<T> $list
     *
     * @return T
     */
    private static function first(array $list): mixed
    {
        return $list[0] ?? throw new \LogicException('Expected a non-empty list');
    }

    // ---- balances ----

    #[Test]
    public function balanceSendsTheCurrencyAndKeepsTheAmountExact(): void
    {
        $http = new FakeHttpClient(self::ok(['wallet_balance' => self::money('12345678901234567890.123456789')]));

        $balance = $this->api($http)->balance(Currency::Usd);

        self::assertSame([['WalletBalance', ['currency' => 'USD']]], $http->operations());
        self::assertSame('12345678901234567890.123456789', $balance->amount->toString());
        self::assertSame(Currency::Usd, $balance->currency);
    }

    #[Test]
    public function balancesMapsEveryCurrencyAndKeepsAnUnknownOne(): void
    {
        $http = new FakeHttpClient(self::ok(['wallet_balances' => [self::money('1.50'), self::money('0.10', 'EUR')]]));

        $balances = $this->api($http)->balances();

        self::assertCount(2, $balances);
        self::assertSame('1.50', $balances[0]->amount->toString());
        self::assertSame('0.10', $balances[1]->amount->toString());
        self::assertInstanceOf(UnknownEnumValue::class, $balances[1]->currency);
        self::assertSame('EUR', $balances[1]->currency->value);
    }

    #[Test]
    public function balancesOfAnEmptyAccountIsAnEmptyList(): void
    {
        self::assertSame([], $this->api(new FakeHttpClient(self::ok(['wallet_balances' => []])))->balances());
    }

    #[Test]
    public function aMalformedBalanceNamesItsPath(): void
    {
        $api = $this->api(new FakeHttpClient(self::ok(['wallet_balances' => [self::money('1.00'), self::money('1e3')]])));

        try {
            $api->balances();
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('wallet_balances[1].value', $e->path);
        }
    }

    // ---- deposits / withdrawals ----

    #[Test]
    public function depositsMapsEveryField(): void
    {
        $node = SyntheticResult::of(DepositResult::class, [
            'id' => 'dep-1',
            'createdAt' => '2026-10-30T10:00:00+00:00',
            'completedAt' => '2026-10-30T10:05:00+00:00',
            'expiredAt' => '2026-10-31T10:00:00+00:00',
            'provider' => 'AIRWALLEX',
            'status' => 'PROCESSING',
            'amount' => self::money('100.05'),
            'comment' => 'a comment',
            'providerCorrespondingId' => 'prov-1',
            'providerPaymentPage' => 'https://example.invalid/pay',
            'airwallexSecret' => 'synthetic-airwallex-secret',
            'person' => self::person('p-1'),
        ]);
        $http = new FakeHttpClient(self::ok(['wallet_deposits' => [$node]]));

        $deposits = $this->api($http)->deposits();

        self::assertSame([['WalletDeposits', []]], $http->operations());
        self::assertCount(1, $deposits);
        $deposit = $deposits[0];
        self::assertSame('dep-1', $deposit->id);
        self::assertSame('2026-10-30T10:00:00+00:00', $deposit->createdAt->format('c'));
        self::assertSame('2026-10-30T10:05:00+00:00', $deposit->completedAt?->format('c'));
        self::assertSame('2026-10-31T10:00:00+00:00', $deposit->expiredAt?->format('c'));
        self::assertSame(PaymentProvider::Airwallex, $deposit->provider);
        self::assertSame(DepositStatus::Processing, $deposit->status);
        self::assertSame('100.05', $deposit->amount->amount->toString());
        self::assertSame('a comment', $deposit->comment);
        self::assertSame('prov-1', $deposit->providerCorrespondingId);
        self::assertSame('https://example.invalid/pay', $deposit->providerPaymentPage);
        self::assertInstanceOf(Secret::class, $deposit->airwallexSecret);
        self::assertSame('synthetic-airwallex-secret', $deposit->airwallexSecret->reveal());
        self::assertSame('p-1', $deposit->person->id);
        self::assertSame('76561190000000001', $deposit->person->steamId);
        self::assertSame(7, $deposit->person->steamLevel);
        self::assertSame(DeliveryPromise::Hour1, $deposit->person->deliveryPromise);
    }

    #[Test]
    public function aDepositDoesNotShowItsSecretWhenDumped(): void
    {
        $node = SyntheticResult::of(DepositResult::class, ['airwallexSecret' => 'synthetic-airwallex-secret', 'amount' => self::money('1.00')]);
        $deposit = self::first($this->api(new FakeHttpClient(self::ok(['wallet_deposits' => [$node]])))->deposits());

        foreach (DebugOutput::all($deposit) as $output) {
            self::assertStringNotContainsString('synthetic-airwallex-secret', $output);
        }
    }

    #[Test]
    public function aDepositWithoutASecretHasNone(): void
    {
        $node = SyntheticResult::of(DepositResult::class, ['amount' => self::money('1.00')]);

        $deposit = self::first($this->api(new FakeHttpClient(self::ok(['wallet_deposits' => [$node]])))->deposits());

        self::assertNull($deposit->airwallexSecret);
        self::assertNull($deposit->completedAt);
        self::assertNull($deposit->comment);
    }

    #[Test]
    public function unknownDepositEnumValuesAreKeptNotThrown(): void
    {
        $node = SyntheticResult::of(DepositResult::class, ['provider' => 'NEW_PROVIDER', 'status' => 'NEW_STATUS', 'amount' => self::money('1.00'), 'person' => self::person('p', ['deliveryPromise' => 'NEW_PROMISE'])]);

        $deposit = self::first($this->api(new FakeHttpClient(self::ok(['wallet_deposits' => [$node]])))->deposits());

        self::assertInstanceOf(UnknownEnumValue::class, $deposit->provider);
        self::assertSame('NEW_PROVIDER', $deposit->provider->value);
        self::assertInstanceOf(UnknownEnumValue::class, $deposit->status);
        self::assertSame('NEW_STATUS', $deposit->status->value);
        self::assertInstanceOf(UnknownEnumValue::class, $deposit->person->deliveryPromise);
        self::assertSame('NEW_PROMISE', $deposit->person->deliveryPromise->value);
    }

    #[Test]
    public function withdrawalsMapsEveryField(): void
    {
        $node = SyntheticResult::of(WithdrawalResult::class, [
            'id' => 'wd-1',
            'createdAt' => '2026-10-30T10:00:00+00:00',
            'completedAt' => '2026-10-30T11:00:00+00:00',
            'expiredAt' => '2026-10-31T10:00:00+00:00',
            'provider' => 'SEPA',
            'status' => 'SUCCESS',
            'amount' => self::money('7.77'),
            'comment' => 'note',
            'providerCorrespondingId' => 'prov-9',
            'person' => self::person('p-2'),
        ]);
        $http = new FakeHttpClient(self::ok(['wallet_withdrawals' => [$node]]));

        $withdrawals = $this->api($http)->withdrawals();

        self::assertSame([['WalletWithdrawals', []]], $http->operations());
        $w = self::first($withdrawals);
        self::assertSame('wd-1', $w->id);
        self::assertSame('2026-10-30T11:00:00+00:00', $w->completedAt?->format('c'));
        self::assertSame('2026-10-31T10:00:00+00:00', $w->expiredAt?->format('c'));
        self::assertSame(PaymentProvider::Sepa, $w->provider);
        self::assertSame(WithdrawalStatus::Success, $w->status);
        self::assertSame('7.77', $w->amount->amount->toString());
        self::assertSame('note', $w->comment);
        self::assertSame('prov-9', $w->providerCorrespondingId);
        self::assertSame('p-2', $w->person->id);
    }

    #[Test]
    public function aNewWithdrawalStatusIsKept(): void
    {
        $node = SyntheticResult::of(WithdrawalResult::class, ['status' => 'REVIEW', 'amount' => self::money('1.00')]);

        $w = self::first($this->api(new FakeHttpClient(self::ok(['wallet_withdrawals' => [$node]])))->withdrawals());

        self::assertInstanceOf(UnknownEnumValue::class, $w->status);
        self::assertSame('REVIEW', $w->status->value);
    }

    // ---- transactions ----

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
    public function transactionsSendForwardPaginationOnlyWhenGiven(?PageRequest $page, array $expected): void
    {
        $http = new FakeHttpClient(self::ok(self::transactionsPage([], null, false)));

        $this->api($http)->transactions(null, $page);

        self::assertSame([['WalletTransactions', $expected]], $http->operations());
    }

    #[Test]
    public function transactionsMapEveryField(): void
    {
        $node = SyntheticResult::of(TransactionNode::class, [
            'id' => 't-1',
            'createdAt' => '2026-10-30T10:00:00+00:00',
            'reason' => 'MARKET_PRODUCT',
            'reasonId' => 'deal-77',
            'status' => 'SUCCESS_HOLD',
            'amount' => self::money('-10.10'),
            'fee' => self::money('0.31'),
            'comment' => 'c',
            'initialCurrency' => 'USDT',
            'marketDealActionType' => 'SELL',
            'method' => 'WHITEBIT_CODE',
            'network' => 'TRC20',
            'withdrawalAddress' => 'synthetic-address',
            'person' => self::person('me'),
            'counterparty' => self::person('them', ['steamId' => '76561190000000002']),
        ]);
        $http = new FakeHttpClient(self::ok(['wallet_transactions' => [
            'edges' => [['cursor' => 'c1', 'node' => $node]],
            'pageInfo' => SyntheticResult::of(TransactionsPageInfo::class, ['endCursor' => 'c1', 'hasNextPage' => true]),
            'totalCount' => 31,
        ]]));

        $page = $this->api($http)->transactions();

        self::assertSame('c1', $page->endCursor);
        self::assertTrue($page->hasNextPage);
        self::assertSame(31, $page->totalCount);
        $t = self::first($page->items);
        self::assertSame('t-1', $t->id);
        self::assertSame(TransactionReason::MarketProduct, $t->reason);
        self::assertSame('deal-77', $t->reasonId);
        self::assertSame(TransactionStatus::SuccessHold, $t->status);
        self::assertSame('-10.10', $t->amount->amount->toString());
        self::assertSame('0.31', $t->fee?->amount->toString());
        self::assertSame('c', $t->comment);
        self::assertSame('USDT', $t->initialCurrency?->value);
        self::assertSame(DealAction::Sell, $t->dealAction);
        self::assertSame('WHITEBIT_CODE', $t->method?->value);
        self::assertSame('TRC20', $t->network?->value);
        self::assertSame('synthetic-address', $t->withdrawalAddress);
        self::assertSame('me', $t->person->id);
        self::assertSame('them', $t->counterparty?->id);
        self::assertSame('76561190000000002', $t->counterparty->steamId);
    }

    #[Test]
    public function aTransactionWithoutOptionalPartsHasNulls(): void
    {
        $node = SyntheticResult::of(TransactionNode::class, ['amount' => self::money('1.00')]);

        $t = self::first($this->api(new FakeHttpClient(self::ok(['wallet_transactions' => ['edges' => [['cursor' => 'c', 'node' => $node]], 'pageInfo' => null, 'totalCount' => null]])))->transactions()->items);

        self::assertNull($t->fee);
        self::assertNull($t->counterparty);
        self::assertNull($t->initialCurrency);
        self::assertNull($t->dealAction);
        self::assertNull($t->method);
        self::assertNull($t->network);
        self::assertNull($t->withdrawalAddress);
    }

    #[Test]
    public function unknownTransactionEnumValuesAreKept(): void
    {
        $node = SyntheticResult::of(TransactionNode::class, [
            'amount' => self::money('1.00'), 'reason' => 'R_NEW', 'status' => 'S_NEW', 'initialCurrency' => 'C_NEW',
            'marketDealActionType' => 'A_NEW', 'method' => 'M_NEW', 'network' => 'N_NEW',
        ]);

        $t = self::first($this->api(new FakeHttpClient(self::ok(['wallet_transactions' => ['edges' => [['cursor' => 'c', 'node' => $node]], 'pageInfo' => null, 'totalCount' => null]])))->transactions()->items);

        foreach ([[$t->reason, 'R_NEW'], [$t->status, 'S_NEW'], [$t->initialCurrency, 'C_NEW'], [$t->dealAction, 'A_NEW'], [$t->method, 'M_NEW'], [$t->network, 'N_NEW']] as [$value, $wire]) {
            self::assertInstanceOf(UnknownEnumValue::class, $value);
            self::assertSame($wire, $value->value);
        }
    }

    #[Test]
    public function transactionsSkipNullEdgesAndNodesAndTolerateNullLists(): void
    {
        $node = SyntheticResult::of(TransactionNode::class, ['id' => 'kept', 'amount' => self::money('1.00')]);
        $api = $this->api(new FakeHttpClient(
            self::ok(['wallet_transactions' => ['edges' => [null, ['cursor' => 'x', 'node' => null], ['cursor' => 'c', 'node' => $node]], 'pageInfo' => null, 'totalCount' => null]]),
            self::ok(['wallet_transactions' => ['edges' => null, 'pageInfo' => null, 'totalCount' => null]]),
        ));

        $first = $api->transactions();
        $second = $api->transactions();

        self::assertSame(['kept'], array_map(static fn(WalletTransaction $t): string => $t->id, $first->items));
        self::assertFalse($first->hasNextPage);
        self::assertNull($first->endCursor);
        self::assertSame([], $second->items);
    }

    #[Test]
    public function transactionsEncodeAFilter(): void
    {
        $http = new FakeHttpClient(self::ok(self::transactionsPage([], null, false)));
        $filter = new WalletTransactionFilter(reasons: [TransactionReason::Transfer], sort: new WalletTransactionSort(TransactionSortField::Amount));

        $this->api($http)->transactions($filter);

        self::assertSame(
            [['WalletTransactions', ['search' => ['reasons' => ['TRANSFER'], 'sort' => ['field' => 'AMOUNT', 'type' => 'ASC']]]]],
            $http->operations(),
        );
    }

    #[Test]
    public function transactionsSendARawInputExactly(): void
    {
        $http = new FakeHttpClient(self::ok(self::transactionsPage([], null, false)));

        $this->api($http)->transactions(new WalletTransactionSearchInput(isBuyer: false));

        self::assertSame([['WalletTransactions', ['search' => ['isBuyer' => false]]]], $http->operations());
    }

    #[Test]
    public function iterateTransactionsMakesNoRequestUntilItIsConsumed(): void
    {
        $http = new FakeHttpClient(self::ok(self::transactionsPage(['a', 'b'], 'c2', true)), self::ok(self::transactionsPage(['c'], null, false)));

        $items = $this->api($http)->iterateTransactions();
        self::assertCount(0, $http->requests(), 'created, not started');

        self::assertSame('a', $items->current()->id);
        $items->next();
        self::assertSame('b', $items->current()->id);
        self::assertCount(1, $http->requests(), 'last item of page 1: page 2 is not requested yet');

        $items->next();
        self::assertSame('c', $items->current()->id);
        self::assertCount(2, $http->requests());
    }

    #[Test]
    public function iterateTransactionsFollowsCursorsKeepingTheFilter(): void
    {
        $http = new FakeHttpClient(self::ok(self::transactionsPage(['a', 'b'], 'c2', true)), self::ok(self::transactionsPage(['c'], null, false)));

        $keyed = [];
        foreach ($this->api($http)->iterateTransactions(new WalletTransactionFilter(buyer: true), 2) as $key => $t) {
            $keyed[$key] = $t->id;
        }

        self::assertSame([0 => 'a', 1 => 'b', 2 => 'c'], $keyed);
        self::assertSame(
            [
                ['WalletTransactions', ['forwardPagination' => ['first' => 2], 'search' => ['isBuyer' => true]]],
                ['WalletTransactions', ['forwardPagination' => ['after' => 'c2', 'first' => 2], 'search' => ['isBuyer' => true]]],
            ],
            $http->operations(),
        );
    }

    #[Test]
    public function iterateTransactionsNamesTheListInTheErrorPathOfAMissingCursor(): void
    {
        $api = $this->api(new FakeHttpClient(self::ok(self::transactionsPage(['a'], null, true))));

        try {
            iterator_to_array($api->iterateTransactions(), false);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('wallet_transactions.pageInfo.endCursor', $e->path);
        }
    }

    // ---- transfers ----

    #[Test]
    public function transfersMapEveryField(): void
    {
        $node = SyntheticResult::of(TransferNode::class, [
            'id' => 'tr-1',
            'createdAt' => '2026-10-30T10:00:00+00:00',
            'completedAt' => '2026-10-30T10:01:00+00:00',
            'status' => 'PROCESSING',
            'amount' => self::money('3.30'),
            'comment' => 'hi',
            'from' => self::person('a'),
            'to' => self::person('b'),
        ]);
        $http = new FakeHttpClient(self::ok(['wallet_transfers' => [
            'edges' => [['cursor' => 'c', 'node' => $node]],
            'pageInfo' => SyntheticResult::of(TransfersPageInfo::class, ['endCursor' => 'c', 'hasNextPage' => false]),
            'totalCount' => 1,
        ]]));

        $page = $this->api($http)->transfers(new PageRequest(10));

        self::assertSame([['WalletTransfers', ['forwardPagination' => ['first' => 10]]]], $http->operations());
        $t = self::first($page->items);
        self::assertSame('tr-1', $t->id);
        self::assertSame('2026-10-30T10:01:00+00:00', $t->completedAt?->format('c'));
        self::assertSame(TransferStatus::Processing, $t->status);
        self::assertSame('3.30', $t->amount->amount->toString());
        self::assertSame('hi', $t->comment);
        self::assertSame('a', $t->from->id);
        self::assertSame('b', $t->to->id);
        self::assertSame(1, $page->totalCount);
    }

    #[Test]
    public function aNewTransferStatusIsKept(): void
    {
        $node = SyntheticResult::of(TransferNode::class, ['status' => 'HELD', 'amount' => self::money('1.00')]);

        $t = self::first($this->api(new FakeHttpClient(self::ok(['wallet_transfers' => ['edges' => [['cursor' => 'c', 'node' => $node]], 'pageInfo' => null, 'totalCount' => null]])))->transfers()->items);

        self::assertInstanceOf(UnknownEnumValue::class, $t->status);
        self::assertSame('HELD', $t->status->value);
    }

    #[Test]
    public function iterateTransfersIsLazyAndFollowsCursors(): void
    {
        $http = new FakeHttpClient(self::ok(self::transfersPage(['a', 'b'], 'c2', true)), self::ok(self::transfersPage(['c'], null, false)));

        $items = $this->api($http)->iterateTransfers(2);
        self::assertCount(0, $http->requests());

        $ids = [];
        foreach ($items as $key => $transfer) {
            $ids[$key] = $transfer->id;
        }

        self::assertSame([0 => 'a', 1 => 'b', 2 => 'c'], $ids);
        self::assertSame(
            [
                ['WalletTransfers', ['forwardPagination' => ['first' => 2]]],
                ['WalletTransfers', ['forwardPagination' => ['after' => 'c2', 'first' => 2]]],
            ],
            $http->operations(),
        );
    }

    // ---- credit ----

    #[Test]
    public function creditReadsTheThreeFiguresInOneQueryDocument(): void
    {
        $http = new FakeHttpClient(self::ok([
            'wallet_credit_max' => self::money('1000.00'),
            'wallet_credit_used' => self::money('250.5'),
            'wallet_credit_available' => self::money('749.50'),
        ]));

        $credit = $this->api($http)->credit();

        self::assertCount(1, $http->requests());
        $payload = $http->lastPayload();
        self::assertSame('WalletCredit', $payload['operationName'] ?? null);
        $query = $payload['query'] ?? null;
        self::assertIsString($query);
        foreach (['wallet_credit_max', 'wallet_credit_used', 'wallet_credit_available'] as $field) {
            self::assertStringContainsString($field . ' { value currency }', $query);
        }
        self::assertStringNotContainsString('mutation', $query);
        self::assertSame('1000.00', $credit->max->amount->toString());
        self::assertSame('250.5', $credit->used->amount->toString());
        self::assertSame('749.50', $credit->available->amount->toString());
        self::assertSame(Currency::Usd, $credit->max->currency);
    }

    #[Test]
    public function creditIsRetriedLikeAQuery(): void
    {
        $http = new FakeHttpClient(
            FakeHttpClient::status(503),
            self::ok(['wallet_credit_max' => self::money('1.00'), 'wallet_credit_used' => self::money('0.00'), 'wallet_credit_available' => self::money('1.00')]),
        );

        $credit = $this->api($http, new RetryPolicy(maxAttempts: 2))->credit();

        self::assertCount(2, $http->requests());
        self::assertSame('1.00', $credit->max->amount->toString());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function creditFields(): iterable
    {
        yield 'max' => ['wallet_credit_max'];
        yield 'used' => ['wallet_credit_used'];
        yield 'available' => ['wallet_credit_available'];
    }

    #[Test]
    #[DataProvider('creditFields')]
    public function creditWithAMissingFieldIsADecodeError(string $missing): void
    {
        $data = ['wallet_credit_max' => self::money('1.00'), 'wallet_credit_used' => self::money('0.00'), 'wallet_credit_available' => self::money('1.00')];
        unset($data[$missing]);
        $api = $this->api(new FakeHttpClient(self::ok($data)));

        try {
            $api->credit();
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame($missing, $e->path);
        }
    }

    // ---- money mutations ----

    /**
     * @return iterable<string, array{\Closure(): FakeHttpClient}>
     */
    public static function ambiguousFailures(): iterable
    {
        yield '503' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::status(503), FakeHttpClient::status(503), FakeHttpClient::status(503))];
        yield '500' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::status(500), FakeHttpClient::status(500), FakeHttpClient::status(500))];
        yield 'network error' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::networkError(), FakeHttpClient::networkError(), FakeHttpClient::networkError())];
        yield 'an AuthenticationException thrown by the client' => [static fn(): FakeHttpClient => new FakeHttpClient(new AuthenticationException(401, ''), new AuthenticationException(401, ''))];
        yield 'malformed 200' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::status(200, 'not json'), FakeHttpClient::status(200, 'not json'))];
        yield 'GraphQL error on a nested path (the mutation ran)' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::json([
            'data' => ['wallet_transfer_send' => null, 'wallet_credit_get' => null],
            'errors' => [['message' => 'Failure', 'path' => ['wallet_transfer_send', 'from'], 'extensions' => ['category' => 'internal']]],
        ]))];
        yield 'a 200 whose result misses required fields' => [static fn(): FakeHttpClient => new FakeHttpClient(self::ok(['wallet_transfer_send' => [], 'wallet_credit_get' => []]))];
    }

    /**
     * @return iterable<string, array{\Closure(string): FakeHttpClient, class-string<WhiteMarketException>}> the argument is the root field
     */
    public static function rejections(): iterable
    {
        yield 'HTTP 400' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::status(400, 'bad')), BadRequestException::class];
        yield 'HTTP 429' => [static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::status(429)), RateLimitException::class];
        yield 'GraphQL root-path error, data null' => [
            static fn(string $field): FakeHttpClient => new FakeHttpClient(FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Rejected', 'path' => [$field], 'extensions' => ['category' => 'wallet.rejected']]]])),
            GraphQLException::class,
        ];
        yield 'GraphQL request error without a path, data null' => [
            static fn(): FakeHttpClient => new FakeHttpClient(FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Rejected']]])),
            GraphQLException::class,
        ];
    }

    private function transferResponse(): ResponseInterface
    {
        return self::ok(['wallet_transfer_send' => SyntheticResult::of(TransferSendResult::class, [
            'id' => 'tr-9',
            'createdAt' => '2026-10-30T10:00:00+00:00',
            'status' => 'NEW',
            'amount' => self::money('5.25'),
            'comment' => 'sent',
            'from' => self::person('me'),
            'to' => self::person('you'),
        ])]);
    }

    #[Test]
    public function transferSendsTheRecipientAndAmountOnceAndMapsTheResult(): void
    {
        $http = new FakeHttpClient($this->transferResponse());

        $transfer = $this->api($http)->transfer('76561190000000009', TestMoney::usd('5.25'));

        self::assertSame([['WalletTransferSend', ['amount' => ['currency' => 'USD', 'value' => '5.25'], 'to' => '76561190000000009']]], $http->operations());
        self::assertSame('tr-9', $transfer->id);
        self::assertSame(TransferStatus::New, $transfer->status);
        self::assertSame('5.25', $transfer->amount->amount->toString());
        self::assertSame('sent', $transfer->comment);
        self::assertSame('me', $transfer->from->id);
        self::assertSame('you', $transfer->to->id);
    }

    #[Test]
    public function transferKeepsTheRecipientUnchanged(): void
    {
        $http = new FakeHttpClient($this->transferResponse());

        $this->api($http)->transfer(' padded-id ', TestMoney::usd('0.01'));

        $variables = self::first($http->operations())[1];
        self::assertSame(' padded-id ', $variables['to'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badRecipients(): iterable
    {
        yield 'empty' => [''];
        yield 'newline' => ["id\n"];
        yield 'carriage return inside' => ["a\rb"];
        yield 'tab' => ["a\tb"];
        yield 'NUL' => ["a\0b"];
        yield 'DEL' => ["a\x7Fb"];
        yield 'unit separator (last C0 control)' => ["a\x1Fb"];
        yield 'C1 control U+0080 (first)' => ["a\u{0080}b"];
        yield 'C1 control U+0085 (NEL)' => ["a\u{0085}b"];
        yield 'C1 control U+009F (last)' => ["a\u{009F}b"];
        yield 'invalid UTF-8 byte' => ["bad\xFFid"];
        yield 'truncated UTF-8 sequence' => ["a\xC3"];
    }

    #[Test]
    #[DataProvider('badRecipients')]
    public function transferRefusesABadRecipientBeforeSendingAnything(string $to): void
    {
        $http = new FakeHttpClient();

        try {
            $this->api($http)->transfer($to, TestMoney::usd('1.00'));
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException) {
            self::assertCount(0, $http->requests());
        }
    }

    #[Test]
    public function transferAcceptsAnyOtherCharacter(): void
    {
        foreach (['0', ' ', "a\u{00A0}b", 'é', "\x20", 'Иван Петров', "a\u{00A0}b\u{00A1}"] as $to) {
            $http = new FakeHttpClient($this->transferResponse());

            $this->api($http)->transfer($to, TestMoney::usd('1.00'));

            self::assertCount(1, $http->requests(), \sprintf('recipient %s', bin2hex($to)));
        }
    }

    #[Test]
    public function transferRefusesAnUnknownCurrencyBeforeSendingAnything(): void
    {
        $http = new FakeHttpClient();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->api($http)->transfer('me', new Money(TestMoney::usd('1.00')->amount, new UnknownEnumValue(Currency::class, 'EUR')));
        } finally {
            self::assertCount(0, $http->requests());
        }
    }

    /**
     * @param \Closure(): FakeHttpClient $http
     */
    #[Test]
    #[DataProvider('ambiguousFailures')]
    public function transferIsSentOnceAndAnAmbiguousFailureIsOutcomeUnknown(\Closure $http): void
    {
        $fake = $http();

        try {
            $this->api($fake)->transfer('someone', TestMoney::usd('5.25'));
            self::fail('Expected a MutationOutcomeUnknownException');
        } catch (MutationOutcomeUnknownException $e) {
            self::assertCount(1, $fake->requests());
            self::assertSame('wallet.transfer', $e->operation);
            self::assertInstanceOf(\Throwable::class, $e->getPrevious());
            self::assertStringContainsString('wallet.transfers()', $e->getMessage());
            self::assertStringNotContainsString('5.25', $e->getMessage());
            self::assertStringNotContainsString('someone', $e->getMessage());
        }
    }

    /**
     * The cause handed to the method that wraps it is exported whole, and its trace reaches the caller's frame, which
     * holds the recipient.
     */
    #[Test]
    public function anAmbiguousTransferKeepsTheRecipientOutOfEveryTraceFrame(): void
    {
        $api = $this->api(new FakeHttpClient(FakeHttpClient::status(500)), new RetryPolicy(maxAttempts: 1));

        $e = TraceArguments::caught(static function () use ($api): void {
            self::transferTo($api, 'synthetic-recipient-7');
        });

        self::assertInstanceOf(MutationOutcomeUnknownException::class, $e);
        self::assertStringNotContainsString('synthetic-recipient-7', TraceArguments::exportedSdkFrames($e));
    }

    /**
     * A caller frame that holds the recipient unmasked, as user code does; the cause's trace reaches it.
     */
    private static function transferTo(WalletApi $api, string $to): void
    {
        $api->transfer($to, TestMoney::usd('1.00'));
    }

    /**
     * @param \Closure(string): FakeHttpClient $http
     * @param class-string<WhiteMarketException> $expected
     */
    #[Test]
    #[DataProvider('rejections')]
    public function transferPropagatesARejectionAsIs(\Closure $http, string $expected): void
    {
        $fake = $http('wallet_transfer_send');

        try {
            $this->api($fake)->transfer('someone', TestMoney::usd('5.25'));
            self::fail('Expected ' . $expected);
        } catch (WhiteMarketException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertNotInstanceOf(MutationOutcomeUnknownException::class, $e);
            self::assertCount(1, $fake->requests());
        }
    }

    #[Test]
    public function takeCreditSendsTheAmountOnceAndMapsTheDebt(): void
    {
        $http = new FakeHttpClient(self::ok(['wallet_credit_get' => SyntheticResult::of(CreditGetResult::class, [
            'id' => 'debt-1',
            'createdAt' => '2026-10-30T10:00:00+00:00',
            'amount' => self::money('100.10'),
            'person' => self::person('me'),
        ])]));

        $debt = $this->api($http)->takeCredit(TestMoney::usd('100.10'));

        self::assertSame([['WalletCreditGet', ['moneyInput' => ['currency' => 'USD', 'value' => '100.10']]]], $http->operations());
        self::assertSame('debt-1', $debt->id);
        self::assertSame('2026-10-30T10:00:00+00:00', $debt->createdAt->format('c'));
        self::assertSame('100.10', $debt->amount->amount->toString());
    }

    #[Test]
    public function takeCreditRefusesAnUnknownCurrencyBeforeSendingAnything(): void
    {
        $http = new FakeHttpClient();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $this->api($http)->takeCredit(new Money(TestMoney::usd('1.00')->amount, new UnknownEnumValue(Currency::class, 'EUR')));
        } finally {
            self::assertCount(0, $http->requests());
        }
    }

    /**
     * @param \Closure(): FakeHttpClient $http
     */
    #[Test]
    #[DataProvider('ambiguousFailures')]
    public function takeCreditIsSentOnceAndAnAmbiguousFailureIsOutcomeUnknown(\Closure $http): void
    {
        $fake = $http();

        try {
            $this->api($fake)->takeCredit(TestMoney::usd('100.10'));
            self::fail('Expected a MutationOutcomeUnknownException');
        } catch (MutationOutcomeUnknownException $e) {
            self::assertCount(1, $fake->requests());
            self::assertSame('wallet.takeCredit', $e->operation);
            self::assertStringContainsString('wallet.credit()', $e->getMessage());
            self::assertStringNotContainsString('100.10', $e->getMessage());
        }
    }

    /**
     * @param \Closure(string): FakeHttpClient $http
     * @param class-string<WhiteMarketException> $expected
     */
    #[Test]
    #[DataProvider('rejections')]
    public function takeCreditPropagatesARejectionAsIs(\Closure $http, string $expected): void
    {
        $fake = $http('wallet_credit_get');

        try {
            $this->api($fake)->takeCredit(TestMoney::usd('100.10'));
            self::fail('Expected ' . $expected);
        } catch (WhiteMarketException $e) {
            self::assertInstanceOf($expected, $e);
            self::assertNotInstanceOf(MutationOutcomeUnknownException::class, $e);
            self::assertCount(1, $fake->requests());
        }
    }

    #[Test]
    public function aTransferResultThatFailsToMapIsOutcomeUnknownWithTheCauseKept(): void
    {
        $response = self::ok(['wallet_transfer_send' => SyntheticResult::of(TransferSendResult::class, ['amount' => self::money('not-a-number')])]);
        $http = new FakeHttpClient($response);

        try {
            $this->api($http)->transfer('someone', TestMoney::usd('1.00'));
            self::fail('Expected a MutationOutcomeUnknownException');
        } catch (MutationOutcomeUnknownException $e) {
            self::assertInstanceOf(DecodeException::class, $e->getPrevious());
        }
    }
}
