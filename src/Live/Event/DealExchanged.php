<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

/**
 * The Steam trade offer of the deal was exchanged (`market_deal_exchanged`); not the `COMPLETED` status, that is {@see DealCompleted}.
 */
final readonly class DealExchanged extends DealEvent {}
