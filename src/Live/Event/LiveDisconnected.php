<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Event;

/**
 * The connection ended. `code` is the Centrifugo close or disconnect code, null for a network failure; `reason` is the
 * server's reason made safe by {@see \WhiteMarket\Sdk\Errors\ServerText::printable()} (128 bytes of printable ASCII), or a
 * short SDK description of a deliberate reconnect ('token refresh', 'subscriptions changed', ...). Not a {@see LiveEvent}.
 */
final readonly class LiveDisconnected
{
    public function __construct(
        public ?int $code,
        public string $reason,
        public bool $willReconnect,
    ) {}
}
