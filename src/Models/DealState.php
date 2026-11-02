<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * The cheap status snapshot of a deal returned by `deals.statuses()` (SDK_SPEC 3.4).
 */
final readonly class DealState
{
    /**
     * @param ?bool $isTradeProtected null when the server does not report it
     * @param ?Money $reversePenalty set after a Steam rollback
     * @param ?Money $reverseRefund set after a Steam rollback
     */
    public function __construct(
        public string $id,
        public ?string $customId,
        public DealStatus|UnknownEnumValue $status,
        public DealCancelReason|UnknownEnumValue|null $cancelReason,
        public Money $price,
        public ?string $steamId,
        public ?string $tradeId,
        public ?bool $isTradeProtected,
        public ?\DateTimeImmutable $settlementTimeAt,
        public ?Money $reversePenalty,
        public ?Money $reverseRefund,
    ) {}

    /**
     * Exchanged under Steam Trade Protection: wait until {@see $settlementTimeAt} for `COMPLETED`; the deal can still
     * be reversed until then.
     */
    public function awaitingTradeProtection(): bool
    {
        return $this->status === DealStatus::Exchanged && $this->isTradeProtected === true;
    }
}
