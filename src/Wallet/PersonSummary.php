<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Wallet;

use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * The public profile of a party to a wallet operation. The Steam fields are personal data: do not log them.
 */
final readonly class PersonSummary
{
    /**
     * @param string $id the partner's id on the marketplace
     * @param ?string $steamId 64-bit Steam id, kept as a string
     */
    public function __construct(
        public string $id,
        public ?string $steamId,
        public ?string $steamName,
        public ?string $steamAvatar,
        public ?int $steamLevel,
        public ?\DateTimeImmutable $steamRegisteredAt,
        public DeliveryPromise|UnknownEnumValue|null $deliveryPromise,
    ) {}
}
