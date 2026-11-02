<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\PaymentProvider;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Models\WithdrawalStatus;

/**
 * A withdrawal from the wallet (`wallet.withdrawals()`).
 */
final readonly class Withdrawal
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $completedAt,
        public ?\DateTimeImmutable $expiredAt,
        public PaymentProvider|UnknownEnumValue $provider,
        public WithdrawalStatus|UnknownEnumValue $status,
        public Money $amount,
        public ?string $comment,
        public ?string $providerCorrespondingId,
        public PersonSummary $person,
    ) {}
}
