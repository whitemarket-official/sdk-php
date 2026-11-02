<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\TransferStatus;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * A transfer of money between two partners (`wallet.transfers()`, `wallet.transfer()`).
 */
final readonly class WalletTransfer
{
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $completedAt,
        public TransferStatus|UnknownEnumValue $status,
        public Money $amount,
        public ?string $comment,
        public PersonSummary $from,
        public PersonSummary $to,
    ) {}
}
