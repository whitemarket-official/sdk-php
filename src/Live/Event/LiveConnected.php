<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

/**
 * The connection is up and subscribed. `reconnected` is true for every connection after the first of a session: events
 * published in the gap are lost (the server sends no history), so the caller re-reads what it cares about. Not a {@see LiveEvent}.
 */
final readonly class LiveConnected
{
    public function __construct(
        public bool $reconnected,
    ) {}
}
