<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Wallet;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\SortDirection;
use WhiteMarket\Sdk\Models\TransactionFeeType;
use WhiteMarket\Sdk\Models\TransactionReason;
use WhiteMarket\Sdk\Models\TransactionSortField;
use WhiteMarket\Sdk\Models\TransactionStatus;
use WhiteMarket\Sdk\Wallet\WalletTransactionFilter;
use WhiteMarket\Sdk\Wallet\WalletTransactionSort;

#[CoversClass(WalletTransactionFilter::class)]
#[CoversClass(WalletTransactionSort::class)]
final class WalletTransactionFilterTest extends TestCase
{
    /**
     * Each constructor parameter alone -> the encoded `WalletTransactionSearchInput` it must produce.
     *
     * @return \Iterator<string, array{array<string, mixed>, array<string, mixed>}>
     */
    public static function singleParameters(): \Iterator
    {
        $from = new \DateTimeImmutable('2026-10-01T00:00:00+00:00');
        $to = new \DateTimeImmutable('2026-10-31T23:59:59+00:00');

        yield 'empty filter -> nothing' => [[], []];
        yield 'createdFrom only' => [['createdFrom' => $from], ['createdAt' => ['from' => '2026-10-01T00:00:00+00:00']]];
        yield 'createdTo only' => [['createdTo' => $to], ['createdAt' => ['to' => '2026-10-31T23:59:59+00:00']]];
        yield 'createdFrom and createdTo' => [['createdFrom' => $from, 'createdTo' => $to], ['createdAt' => ['from' => '2026-10-01T00:00:00+00:00', 'to' => '2026-10-31T23:59:59+00:00']]];
        yield 'currency' => [['currency' => Currency::Usd], ['currency' => 'USD']];
        yield 'feeType' => [['feeType' => TransactionFeeType::MarketProduct], ['feeType' => 'MARKET_PRODUCT']];
        yield 'initialDepositCurrencies' => [['initialDepositCurrencies' => [Currency::Usd]], ['initialCurrencyDeposit' => ['USD']]];
        yield 'initialWithdrawalCurrencies' => [['initialWithdrawalCurrencies' => [Currency::Usd]], ['initialCurrencyWithdrawal' => ['USD']]];
        yield 'empty currency list is sent' => [['initialDepositCurrencies' => []], ['initialCurrencyDeposit' => []]];
        yield 'buyer -> isBuyer' => [['buyer' => true], ['isBuyer' => true]];
        yield 'buyer false is sent' => [['buyer' => false], ['isBuyer' => false]];
        yield 'seller -> isSeller' => [['seller' => true], ['isSeller' => true]];
        yield 'seller false is sent' => [['seller' => false], ['isSeller' => false]];
        yield 'reason' => [['reason' => TransactionReason::WriteOff], ['reason' => 'WRITE_OFF']];
        yield 'reasons' => [['reasons' => [TransactionReason::Deposit, TransactionReason::Withdrawal]], ['reasons' => ['DEPOSIT', 'WITHDRAWAL']]];
        yield 'sort defaults to ascending' => [['sort' => new WalletTransactionSort(TransactionSortField::CreatedAt)], ['sort' => ['field' => 'CREATED_AT', 'type' => 'ASC']]];
        yield 'sort descending' => [['sort' => new WalletTransactionSort(TransactionSortField::Amount, SortDirection::Desc)], ['sort' => ['field' => 'AMOUNT', 'type' => 'DESC']]];
        yield 'status' => [['status' => TransactionStatus::HoldIncome], ['status' => 'HOLD_INCOME']];
        yield 'depositMethods -> walletMethodsDeposit' => [['depositMethods' => ['SEPA', 'PAYPAL']], ['walletMethodsDeposit' => ['SEPA', 'PAYPAL']]];
        yield 'withdrawalMethods -> walletMethodsWithdrawal' => [['withdrawalMethods' => ['SEPA']], ['walletMethodsWithdrawal' => ['SEPA']]];
    }

    /**
     * @param array<string, mixed> $arguments named constructor arguments
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('singleParameters')]
    public function encodesEachParameterToItsInputField(array $arguments, array $expected): void
    {
        $filter = (new \ReflectionClass(WalletTransactionFilter::class))->newInstanceArgs($arguments);

        self::assertSame($expected, $filter->toInput()->toArray());
    }

    #[Test]
    public function combinesSeveralParameters(): void
    {
        $filter = new WalletTransactionFilter(currency: Currency::Usd, buyer: true, status: TransactionStatus::Success);

        self::assertSame(['currency' => 'USD', 'isBuyer' => true, 'status' => 'SUCCESS'], $filter->toInput()->toArray());
    }

    #[Test]
    public function everyConstructorParameterIsCoveredByASingleParameterCase(): void
    {
        $covered = [];
        foreach (self::singleParameters() as [$arguments]) {
            array_push($covered, ...array_keys($arguments));
        }
        $parameters = array_map(
            static fn(\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionClass(WalletTransactionFilter::class))->getConstructor()?->getParameters() ?? [],
        );

        self::assertSame([], array_values(array_diff($parameters, $covered)));
    }
}
