<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Fragment\PersonPublicFields;
use WhiteMarket\Sdk\Generated\Operation\Mutation\WalletCreditGet\Result as CreditGetResult;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletDeposits\Result as DepositResult;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransactions\EdgesNode as TransactionNode;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransactions\PageInfo as TransactionsPageInfo;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransactions\Result as TransactionsResult;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransfers\EdgesNode;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransfers\PageInfo;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletTransfers\Result;
use WhiteMarket\Sdk\Generated\Operation\Query\WalletWithdrawals\Result as WithdrawalResult;
use WhiteMarket\Sdk\Models\DepositStatus;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\PaymentProvider;
use WhiteMarket\Sdk\Models\TransactionReason;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Models\WithdrawalStatus;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;
use WhiteMarket\Sdk\Wallet\Credit;
use WhiteMarket\Sdk\Wallet\Debt;
use WhiteMarket\Sdk\Wallet\Deposit;
use WhiteMarket\Sdk\Wallet\PersonSummary;
use WhiteMarket\Sdk\Wallet\WalletTransaction;
use WhiteMarket\Sdk\Wallet\WalletTransfer;
use WhiteMarket\Sdk\Wallet\Withdrawal;

#[CoversClass(ModelMapper::class)]
#[CoversClass(PersonSummary::class)]
#[CoversClass(Debt::class)]
#[CoversClass(Credit::class)]
#[CoversClass(WalletTransfer::class)]
#[CoversClass(WalletTransaction::class)]
#[CoversClass(Withdrawal::class)]
#[CoversClass(Deposit::class)]
final class ModelMapperWalletTest extends TestCase
{
    private const MONEY = ['currency' => 'USD', 'value' => '9007199254740993.123456789012345678'];

    #[Test]
    public function personSummaryKeepsTheSteamIdAsAStringAndNullsAsNull(): void
    {
        $person = PersonPublicFields::fromValue(SyntheticResult::of(PersonPublicFields::class, ['id' => 'p', 'steamId' => '76561190000000001']), 'p');

        $summary = (new ModelMapper())->personSummary($person);

        self::assertSame('p', $summary->id);
        self::assertSame('76561190000000001', $summary->steamId);
        self::assertNull($summary->steamName);
        self::assertNull($summary->steamAvatar);
        self::assertNull($summary->steamLevel);
        self::assertNull($summary->steamRegisteredAt);
        self::assertNull($summary->deliveryPromise);
    }

    #[Test]
    public function debtKeepsTheAmountExact(): void
    {
        $debt = (new ModelMapper())->debt(CreditGetResult::fromValue(SyntheticResult::of(CreditGetResult::class, ['id' => 'd', 'amount' => self::MONEY]), 'wallet_credit_get'), 'wallet_credit_get');

        self::assertSame('d', $debt->id);
        self::assertSame('9007199254740993.123456789012345678', $debt->amount->amount->toString());
    }

    #[Test]
    public function aMalformedAmountNamesItsPathInTheTransferPage(): void
    {
        $connection = Result::fromValue([
            'edges' => [null, ['cursor' => 'c', 'node' => SyntheticResult::of(EdgesNode::class, ['amount' => ['currency' => 'USD', 'value' => 'abc']])]],
            'pageInfo' => SyntheticResult::of(PageInfo::class),
            'totalCount' => null,
        ], 'wallet_transfers');

        try {
            (new ModelMapper())->walletTransferPage($connection, 'wallet_transfers');
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('wallet_transfers.edges[1].node.amount.value', $e->path);
        }
    }

    #[Test]
    public function aTransferPageWithoutEdgesOrPageInfoIsEmpty(): void
    {
        $page = (new ModelMapper())->walletTransferPage(Result::fromValue(['edges' => null, 'pageInfo' => null, 'totalCount' => null], 'wallet_transfers'), 'wallet_transfers');

        self::assertSame([], $page->items);
        self::assertFalse($page->hasNextPage);
        self::assertNull($page->endCursor);
        self::assertNull($page->totalCount);
    }

    #[Test]
    public function aMalformedAmountDoesNotLeakItsValueIntoTheExceptionChain(): void
    {
        $raw = 'secret-1234.56x';
        $connection = Result::fromValue([
            'edges' => [['cursor' => 'c', 'node' => SyntheticResult::of(EdgesNode::class, ['amount' => ['currency' => 'USD', 'value' => $raw]])]],
            'pageInfo' => SyntheticResult::of(PageInfo::class),
            'totalCount' => null,
        ], 'wallet_transfers');

        try {
            (new ModelMapper())->walletTransferPage($connection, 'wallet_transfers');
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            for ($link = $e; $link instanceof \Throwable; $link = $link->getPrevious()) {
                self::assertStringNotContainsString($raw, $link->getMessage());
            }
            self::assertNull($e->getPrevious());
            self::assertStringContainsString('not a decimal number', $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function person(string $id): array
    {
        return SyntheticResult::of(PersonPublicFields::class, ['id' => $id, 'steamId' => '76561190000000001']);
    }

    #[Test]
    public function aDepositKeepsTheAmountExactAndTheSecretSecret(): void
    {
        $node = SyntheticResult::of(DepositResult::class, [
            'id' => 'dep',
            'provider' => 'AIRWALLEX',
            'status' => 'PROCESSING',
            'amount' => self::MONEY,
            'airwallexSecret' => 'synthetic-secret',
            'person' => $this->person('p'),
        ]);

        $deposit = (new ModelMapper())->deposit(DepositResult::fromValue($node, 'wallet_deposits[0]'), 'wallet_deposits[0]');

        self::assertSame('dep', $deposit->id);
        self::assertSame(PaymentProvider::Airwallex, $deposit->provider);
        self::assertSame(DepositStatus::Processing, $deposit->status);
        self::assertSame('9007199254740993.123456789012345678', $deposit->amount->amount->toString());
        self::assertInstanceOf(Secret::class, $deposit->airwallexSecret);
        self::assertSame('synthetic-secret', $deposit->airwallexSecret->reveal());
        self::assertSame('p', $deposit->person->id);
    }

    #[Test]
    public function aDepositWithUnknownEnumsAndNoSecretDoesNotThrow(): void
    {
        $node = SyntheticResult::of(DepositResult::class, ['provider' => 'NEW_PROVIDER', 'status' => 'NEW_STATUS', 'amount' => self::MONEY, 'person' => $this->person('p')]);

        $deposit = (new ModelMapper())->deposit(DepositResult::fromValue($node, 'd'), 'd');

        self::assertInstanceOf(UnknownEnumValue::class, $deposit->provider);
        self::assertSame('NEW_PROVIDER', $deposit->provider->value);
        self::assertInstanceOf(UnknownEnumValue::class, $deposit->status);
        self::assertSame('NEW_STATUS', $deposit->status->value);
        self::assertNull($deposit->airwallexSecret);
        self::assertNull($deposit->completedAt);
    }

    #[Test]
    public function aMalformedDepositAmountNamesItsPath(): void
    {
        $node = SyntheticResult::of(DepositResult::class, ['amount' => ['currency' => 'USD', 'value' => '1e3'], 'person' => $this->person('p')]);

        try {
            (new ModelMapper())->deposit(DepositResult::fromValue($node, 'd'), 'wallet_deposits[2]');
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('wallet_deposits[2].amount.value', $e->path);
        }
    }

    #[Test]
    public function aWithdrawalMapsItsFieldsAndUnknownEnums(): void
    {
        $known = SyntheticResult::of(WithdrawalResult::class, ['id' => 'w', 'status' => 'FAILED', 'provider' => 'AIRWALLEX', 'amount' => self::MONEY, 'comment' => 'c', 'providerCorrespondingId' => 'prov', 'person' => $this->person('p')]);
        $unknown = SyntheticResult::of(WithdrawalResult::class, ['status' => 'NEW_STATUS', 'provider' => 'NEW_PROVIDER', 'amount' => self::MONEY, 'person' => $this->person('p')]);

        $withdrawal = (new ModelMapper())->withdrawal(WithdrawalResult::fromValue($known, 'w'), 'w');
        $other = (new ModelMapper())->withdrawal(WithdrawalResult::fromValue($unknown, 'w'), 'w');

        self::assertSame(WithdrawalStatus::Failed, $withdrawal->status);
        self::assertSame('9007199254740993.123456789012345678', $withdrawal->amount->amount->toString());
        self::assertSame('c', $withdrawal->comment);
        self::assertSame('prov', $withdrawal->providerCorrespondingId);
        self::assertInstanceOf(UnknownEnumValue::class, $other->status);
        self::assertSame('NEW_STATUS', $other->status->value);
        self::assertNull($other->comment);
    }

    #[Test]
    public function aTransactionMapsMoneyOptionalFieldsAndCounterparty(): void
    {
        $full = SyntheticResult::of(TransactionNode::class, [
            'reason' => 'MARKET_PRODUCT',
            'amount' => self::MONEY,
            'fee' => ['currency' => 'USD', 'value' => '0.310'],
            'initialCurrency' => 'USDT',
            'person' => $this->person('me'),
            'counterparty' => $this->person('them'),
        ]);
        $bare = SyntheticResult::of(TransactionNode::class, ['reason' => 'NEW_REASON', 'status' => 'NEW_STATUS', 'amount' => self::MONEY, 'person' => $this->person('me')]);

        $mapper = new ModelMapper();
        $t = $mapper->walletTransaction(TransactionNode::fromValue($full, 't'), 't');
        $b = $mapper->walletTransaction(TransactionNode::fromValue($bare, 't'), 't');

        self::assertSame(TransactionReason::MarketProduct, $t->reason);
        self::assertSame('9007199254740993.123456789012345678', $t->amount->amount->toString());
        self::assertSame('0.310', $t->fee?->amount->toString());
        self::assertSame('USDT', $t->initialCurrency?->value);
        self::assertSame('them', $t->counterparty?->id);
        self::assertInstanceOf(UnknownEnumValue::class, $b->reason);
        self::assertSame('NEW_REASON', $b->reason->value);
        self::assertInstanceOf(UnknownEnumValue::class, $b->status);
        self::assertNull($b->fee);
        self::assertNull($b->initialCurrency);
        self::assertNull($b->dealAction);
        self::assertNull($b->method);
        self::assertNull($b->network);
        self::assertNull($b->counterparty);
    }

    #[Test]
    public function aMalformedTransactionFeeNamesItsPath(): void
    {
        $node = SyntheticResult::of(TransactionNode::class, ['amount' => self::MONEY, 'fee' => ['currency' => 'USD', 'value' => 'x'], 'person' => $this->person('me')]);

        try {
            (new ModelMapper())->walletTransaction(TransactionNode::fromValue($node, 't'), 'wallet_transactions.edges[0].node');
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('wallet_transactions.edges[0].node.fee.value', $e->path);
        }
    }

    #[Test]
    public function aTransactionPageSkipsNullEdgesAndNodesAndReadsPageInfo(): void
    {
        $node = SyntheticResult::of(TransactionNode::class, ['id' => 't-2', 'amount' => self::MONEY, 'person' => $this->person('me')]);
        $connection = TransactionsResult::fromValue([
            'edges' => [null, ['cursor' => 'c0', 'node' => null], ['cursor' => 'c1', 'node' => $node]],
            'pageInfo' => SyntheticResult::of(TransactionsPageInfo::class, ['endCursor' => 'c1', 'hasNextPage' => true]),
            'totalCount' => 3,
        ], 'wallet_transactions');

        $page = (new ModelMapper())->walletTransactionPage($connection, 'wallet_transactions');

        self::assertCount(1, $page->items);
        self::assertSame('t-2', $page->items[0]->id);
        self::assertSame('c1', $page->endCursor);
        self::assertTrue($page->hasNextPage);
        self::assertSame(3, $page->totalCount);
    }

    #[Test]
    public function aTransactionPageWithoutEdgesOrPageInfoIsEmpty(): void
    {
        $page = (new ModelMapper())->walletTransactionPage(TransactionsResult::fromValue(['edges' => null, 'pageInfo' => null, 'totalCount' => null], 'wallet_transactions'), 'wallet_transactions');

        self::assertSame([], $page->items);
        self::assertFalse($page->hasNextPage);
        self::assertNull($page->endCursor);
    }

    #[Test]
    public function listMapsEveryEntryWithItsIndexedPath(): void
    {
        $paths = (new ModelMapper())->list(['a', 'b'], 'things', static fn(string $source, string $path): string => $source . '@' . $path);

        self::assertSame(['a@things[0]', 'b@things[1]'], $paths);
        self::assertSame([], (new ModelMapper())->list([], 'things', static fn(): never => self::fail('nothing to map')));
    }
}
