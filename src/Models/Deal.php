<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

use WhiteMarket\Sdk\Auth\Secret;

/**
 * A deal, in progress or archived (SDK_SPEC 3.4: `deals.get()` returns both as one model).
 *
 * The server keeps a deal in progress until it finishes and then moves it to the history; some fields exist on only
 * one side and are null on the other, as noted per parameter.
 */
final readonly class Deal
{
    /**
     * @param ?bool $isTradeProtected in progress only
     * @param ?\DateTimeImmutable $settlementTimeAt in progress only: when Steam Trade Protection ends
     * @param ?\DateTimeImmutable $expiresAt in progress only
     * @param bool $isCancelByBuyerSupported in progress only; always false for an archived deal
     * @param ?int $cancelByBuyerNeedWait in progress only: seconds until the buyer may request a cancel
     * @param DealCancelReason|UnknownEnumValue|null $cancelReason archived only
     * @param ?\DateTimeImmutable $archivedAt archived only
     * @param ?Money $reversePenalty archived only, after a Steam rollback
     * @param ?Money $reverseRefund archived only, after a Steam rollback
     * @param ?InventoryItem $item in progress only; an archived deal's item is {@see ProductHistory::$item}
     * @param ?Secret $receiverTradeUrl in progress only: where a MANUAL seller sends the item; carries the trade token
     */
    public function __construct(
        public string $id,
        public ?string $customId,
        public DealStatus|UnknownEnumValue $status,
        public Money $price,
        public \DateTimeImmutable $createdAt,
        public DeliveryType|UnknownEnumValue $deliveryType,
        public ?string $steamId,
        public ?string $tradeId,
        public ?bool $isTradeProtected,
        public ?\DateTimeImmutable $settlementTimeAt,
        public ?\DateTimeImmutable $expiresAt,
        public bool $isCancelByBuyerSupported,
        public ?int $cancelByBuyerNeedWait,
        public DealCancelReason|UnknownEnumValue|null $cancelReason,
        public ?\DateTimeImmutable $archivedAt,
        public ?Money $reversePenalty,
        public ?Money $reverseRefund,
        public ProductHistory $product,
        public ?InventoryItem $item,
        #[\SensitiveParameter]
        public ?Secret $receiverTradeUrl = null,
    ) {}

    public function isArchived(): bool
    {
        return $this->archivedAt instanceof \DateTimeImmutable;
    }

    /**
     * Exchanged under Steam Trade Protection: wait until {@see $settlementTimeAt} for `COMPLETED`; the deal can still
     * be reversed until then.
     */
    public function awaitingTradeProtection(): bool
    {
        return $this->status === DealStatus::Exchanged && $this->isTradeProtected === true;
    }
}
