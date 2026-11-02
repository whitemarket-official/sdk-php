<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * A deal on the personal channel changed (`market_deal_*`). A notification only: fetch the authoritative state with
 * `deals->statuses()`. `action` is the side the partner is on; `steamId` is the counterparty's Steam id as sent
 * (personal data, the SDK never logs it); `extra` holds the content keys this SDK version does not map.
 */
abstract readonly class DealEvent implements LiveEvent
{
    /**
     * @param array<string, mixed> $extra
     */
    public function __construct(
        public string $channel,
        public string $dealId,
        public DealAction|UnknownEnumValue $action,
        public ?string $productName,
        public ?string $steamId,
        public array $extra = [],
    ) {}
}
