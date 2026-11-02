<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\Input\WalletTransactionSearchInput;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\TransactionFeeType;
use WhiteMarket\Sdk\Models\TransactionReason;
use WhiteMarket\Sdk\Models\TransactionStatus;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * Conditions of a wallet transaction list (SDK_SPEC 3.8): every parameter is optional and named, and the ones given are
 * combined with AND. Pass a raw `WalletTransactionSearchInput` to `wallet->transactions()` for anything this class does
 * not cover.
 */
final readonly class WalletTransactionFilter
{
    /**
     * @param ?\DateTimeInterface $createdFrom earliest creation time
     * @param ?\DateTimeInterface $createdTo latest creation time
     * @param list<Currency>|null $initialDepositCurrencies currencies a deposit was made in
     * @param list<Currency>|null $initialWithdrawalCurrencies currencies a withdrawal was made in
     * @param ?bool $buyer only transactions of deals where the partner bought
     * @param ?bool $seller only transactions of deals where the partner sold
     * @param list<TransactionReason>|null $reasons
     * @param list<string>|null $depositMethods wallet methods of deposits, as the API names them
     * @param list<string>|null $withdrawalMethods wallet methods of withdrawals, as the API names them
     */
    public function __construct(
        public ?\DateTimeInterface $createdFrom = null,
        public ?\DateTimeInterface $createdTo = null,
        public ?Currency $currency = null,
        public ?TransactionFeeType $feeType = null,
        public ?array $initialDepositCurrencies = null,
        public ?array $initialWithdrawalCurrencies = null,
        public ?bool $buyer = null,
        public ?bool $seller = null,
        public ?TransactionReason $reason = null,
        public ?array $reasons = null,
        public ?WalletTransactionSort $sort = null,
        public ?TransactionStatus $status = null,
        public ?array $depositMethods = null,
        public ?array $withdrawalMethods = null,
    ) {}

    /**
     * @internal used by {@see WalletApi}
     */
    public function toInput(): WalletTransactionSearchInput
    {
        $wire = new WireEncoder();

        return new WalletTransactionSearchInput(
            createdAt: $wire->dateRange($this->createdFrom, $this->createdTo),
            currency: $wire->enum(Wire\Currency::class, $this->currency),
            feeType: $wire->enum(Wire\WalletTransactionFeeType::class, $this->feeType),
            initialCurrencyDeposit: $wire->enumList(Wire\Currency::class, $this->initialDepositCurrencies),
            initialCurrencyWithdrawal: $wire->enumList(Wire\Currency::class, $this->initialWithdrawalCurrencies),
            isBuyer: $this->buyer,
            isSeller: $this->seller,
            reason: $wire->enum(Wire\WalletTransactionReason::class, $this->reason),
            reasons: $wire->enumList(Wire\WalletTransactionReason::class, $this->reasons),
            sort: $this->sort?->toInput(),
            status: $wire->enum(Wire\WalletTransactionStatus::class, $this->status),
            walletMethodsDeposit: $this->depositMethods,
            walletMethodsWithdrawal: $this->withdrawalMethods,
        );
    }
}
