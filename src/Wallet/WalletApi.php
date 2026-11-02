<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\MutationOutcomeUnknownException;
use WhiteMarket\Sdk\Errors\MutationRejection;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Fragment\MoneyFields;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\WalletTransactionSearchInput;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\Page;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\Paginator;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * `$client->wallet` (SDK_SPEC 3.8): balances, deposits, withdrawals, transactions, transfers and the credit line.
 *
 * Everything that reads is a query, so the transport retries it on transient failures (SDK_SPEC 1.4).
 * {@see takeCredit()} and {@see transfer()} move money and are mutations: each is sent exactly once and never retried
 * (CLAUDE.md rule 2), and neither has an idempotency key. If one fails in a way that does not tell whether it ran (a
 * timeout, a 5xx, an unreadable response), the SDK throws {@see MutationOutcomeUnknownException}: the money may have
 * moved, so check before sending again. A failure that proves the server did not run it (a malformed request, a rejected
 * credential, a rate limit, a GraphQL error of the root field) propagates unchanged.
 */
final readonly class WalletApi
{
    /** One document, so the three figures come from one consistent read (SDK_SPEC 3.8). Same selection as `MoneyFields`. */
    private const CREDIT_QUERY = 'query WalletCredit { '
        . 'wallet_credit_max { value currency } '
        . 'wallet_credit_used { value currency } '
        . 'wallet_credit_available { value currency } }';

    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private GeneratedClient $api,
        private ModelMapper $mapper,
        private WireEncoder $wire = new WireEncoder(),
        private MutationRejection $rejection = new MutationRejection(),
    ) {}

    /**
     * The balance in one currency (`wallet_balance`).
     *
     * @throws \InvalidArgumentException the currency is not one this SDK knows
     * @throws WhiteMarketException
     */
    public function balance(Currency $currency): Money
    {
        return $this->mapper->money(
            $this->api->walletBalance($this->wire->enum(Wire\Currency::class, $currency)),
            'wallet_balance',
        );
    }

    /**
     * The balances in every currency (`wallet_balances`).
     *
     * @return list<Money>
     *
     * @throws WhiteMarketException
     */
    public function balances(): array
    {
        return $this->mapper->list($this->api->walletBalances(), 'wallet_balances', $this->mapper->money(...));
    }

    /**
     * @return list<Deposit>
     *
     * @throws WhiteMarketException
     */
    public function deposits(): array
    {
        return $this->mapper->list($this->api->walletDeposits(), 'wallet_deposits', $this->mapper->deposit(...));
    }

    /**
     * @return list<Withdrawal>
     *
     * @throws WhiteMarketException
     */
    public function withdrawals(): array
    {
        return $this->mapper->list($this->api->walletWithdrawals(), 'wallet_withdrawals', $this->mapper->withdrawal(...));
    }

    /**
     * One page of wallet transactions (`wallet_transactions`). Pass `new PageRequest(after: $previous->endCursor)` for
     * the next page while `$previous->hasNextPage` is true, or use {@see iterateTransactions()}.
     *
     * @param WalletTransactionFilter|WalletTransactionSearchInput|null $filter a raw `WalletTransactionSearchInput` is sent as is
     * @param ?PageRequest $page page size and cursor; the server's defaults when null
     *
     * @return Page<WalletTransaction>
     *
     * @throws \InvalidArgumentException the filter holds a value the schema does not accept
     * @throws WhiteMarketException
     */
    public function transactions(WalletTransactionFilter|WalletTransactionSearchInput|null $filter = null, ?PageRequest $page = null): Page
    {
        $result = $this->api->walletTransactions(
            $page?->toInput(),
            $filter instanceof WalletTransactionFilter ? $filter->toInput() : $filter,
        );

        return $this->mapper->walletTransactionPage($result, 'wallet_transactions');
    }

    /**
     * Every wallet transaction that matches, page after page; a page is requested only when the previous one is
     * consumed. Keys are 0, 1, 2, ... across pages.
     *
     * @param ?int $pageSize items per request; the server's default when null
     *
     * @return \Generator<int, WalletTransaction>
     *
     * @throws WhiteMarketException
     */
    public function iterateTransactions(WalletTransactionFilter|WalletTransactionSearchInput|null $filter = null, ?int $pageSize = null): \Generator
    {
        return (new Paginator())->iterate(fn(?string $after): Page => $this->transactions($filter, new PageRequest($pageSize, $after)), 'wallet_transactions');
    }

    /**
     * One page of transfers (`wallet_transfers`).
     *
     * @return Page<WalletTransfer>
     *
     * @throws WhiteMarketException
     */
    public function transfers(?PageRequest $page = null): Page
    {
        return $this->mapper->walletTransferPage($this->api->walletTransfers($page?->toInput()), 'wallet_transfers');
    }

    /**
     * Every transfer, page after page, lazily. Keys are 0, 1, 2, ... across pages.
     *
     * @param ?int $pageSize items per request; the server's default when null
     *
     * @return \Generator<int, WalletTransfer>
     *
     * @throws WhiteMarketException
     */
    public function iterateTransfers(?int $pageSize = null): \Generator
    {
        return (new Paginator())->iterate(fn(?string $after): Page => $this->transfers(new PageRequest($pageSize, $after)), 'wallet_transfers');
    }

    /**
     * The credit line: the most that can be owed, what is owed and what is still available. One query with the three
     * root fields (`wallet_credit_max`, `wallet_credit_used`, `wallet_credit_available`), retried like any query.
     *
     * @throws WhiteMarketException
     */
    public function credit(): Credit
    {
        $data = $this->api->execute(self::CREDIT_QUERY);

        return new Credit(
            $this->creditAmount($data, 'wallet_credit_max'),
            $this->creditAmount($data, 'wallet_credit_used'),
            $this->creditAmount($data, 'wallet_credit_available'),
        );
    }

    /**
     * Takes credit (`wallet_credit_get`). Sent once, never retried; if this throws
     * {@see MutationOutcomeUnknownException} the money may have moved: check {@see credit()} before sending again.
     *
     * @throws \InvalidArgumentException the currency of `$amount` is not one this SDK knows
     * @throws WhiteMarketException a rejection (the server did not run it), or {@see MutationOutcomeUnknownException}
     *                              when the request failed in a way that does not tell whether it ran
     */
    public function takeCredit(Money $amount): Debt
    {
        $input = $this->wire->money($amount);

        try {
            return $this->mapper->debt($this->api->walletCreditGet($input), 'wallet_credit_get');
        } catch (\Throwable $e) {
            throw $this->failure($e, 'wallet_credit_get', 'wallet.takeCredit', 'wallet.credit()');
        }
    }

    /**
     * Sends money to another partner (`wallet_transfer_send`). Sent once, never retried; if this throws
     * {@see MutationOutcomeUnknownException} the money may have moved: check {@see transfers()} before sending again.
     *
     * @param string $to a person id or a Steam id, sent unchanged (the API does not document the format)
     *
     * @throws \InvalidArgumentException `$to` is empty, is not valid UTF-8 or holds a control character, or the currency of `$amount` is not one this SDK knows
     * @throws WhiteMarketException a rejection (the server did not run it), or {@see MutationOutcomeUnknownException}
     *                              when the request failed in a way that does not tell whether it ran
     */
    public function transfer(#[\SensitiveParameter] string $to, Money $amount): WalletTransfer
    {
        $this->assertRecipient($to);
        $input = $this->wire->money($amount);

        try {
            return $this->mapper->walletTransfer($this->api->walletTransferSend($input, $to), 'wallet_transfer_send');
        } catch (\Throwable $e) {
            throw $this->failure($e, 'wallet_transfer_send', 'wallet.transfer', 'wallet.transfers()');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    private function assertRecipient(#[\SensitiveParameter] string $to): void
    {
        if ($to === '' || preg_match('//u', $to) !== 1 || preg_match('/\p{Cc}/u', $to) === 1) {
            throw new \InvalidArgumentException('The recipient must be a non-empty UTF-8 person id or Steam id without control characters.');
        }
    }

    /**
     * A rejection (the server did not run the mutation) propagates as it is; anything else, including a broken
     * collaborator, may have happened after the money moved. The failure is sensitive: its trace holds the caller's
     * frames, and with them the recipient.
     */
    private function failure(#[\SensitiveParameter] \Throwable $e, string $field, string $operation, string $checkHint): \Throwable
    {
        return $this->rejection->isRejection($e, $field) ? $e : new MutationOutcomeUnknownException($operation, $e, $checkHint);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws DecodeException
     */
    private function creditAmount(array $data, string $field): Money
    {
        return $this->mapper->money(MoneyFields::fromValue($data[$field] ?? null, $field), $field);
    }
}
