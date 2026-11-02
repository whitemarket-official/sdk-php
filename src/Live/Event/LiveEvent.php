<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

/**
 * One event of a live session (SDK_SPEC 3.11): a hint that something changed, never the authoritative state.
 * `channel` is the Centrifugo channel it arrived on: `market_products_updates`, or `#<personId>` for the personal one.
 *
 * @property-read string $channel
 */
interface LiveEvent {}
