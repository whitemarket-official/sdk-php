<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Models\DepositStatus;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\PaymentProvider;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * A deposit into the wallet (`wallet.deposits()`).
 */
final readonly class Deposit
{
    /**
     * @param ?Secret $airwallexSecret client secret of an Airwallex payment; a credential, masked when dumped
     */
    public function __construct(
        public string $id,
        public \DateTimeImmutable $createdAt,
        public ?\DateTimeImmutable $completedAt,
        public ?\DateTimeImmutable $expiredAt,
        public PaymentProvider|UnknownEnumValue $provider,
        public DepositStatus|UnknownEnumValue $status,
        public Money $amount,
        public ?string $comment,
        public ?string $providerCorrespondingId,
        public ?string $providerPaymentPage,
        #[\SensitiveParameter]
        public ?Secret $airwallexSecret,
        public PersonSummary $person,
    ) {}
}
