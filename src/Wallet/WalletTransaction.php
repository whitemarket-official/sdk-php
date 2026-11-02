<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Models\CryptoCurrency;
use WhiteMarket\Sdk\Models\CryptoNetwork;
use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\TransactionReason;
use WhiteMarket\Sdk\Models\TransactionStatus;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Models\WalletMethod;

/**
 * One movement of money on the wallet (`wallet.transactions()`).
 */
final readonly class WalletTransaction
{
    /**
     * @param string $reasonId id of the object the transaction belongs to (a deal, a deposit, ...), by {@see $reason}
     * @param ?string $withdrawalAddress crypto address of a withdrawal: personal data, do not log it
     * @param ?PersonSummary $counterparty the other party of a transfer or a deal
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public TransactionReason|UnknownEnumValue $reason,
        public string $reasonId,
        public TransactionStatus|UnknownEnumValue $status,
        public Money $amount,
        public ?Money $fee,
        public ?string $comment,
        public CryptoCurrency|UnknownEnumValue|null $initialCurrency,
        public DealAction|UnknownEnumValue|null $dealAction,
        public WalletMethod|UnknownEnumValue|null $method,
        public CryptoNetwork|UnknownEnumValue|null $network,
        public ?string $withdrawalAddress,
        public PersonSummary $person,
        public ?PersonSummary $counterparty,
    ) {}
}
