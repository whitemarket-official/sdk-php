<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Errors\ServerText;
use WhiteMarket\Sdk\Errors\WhiteMarketException;

/**
 * The server closed a WebSocket with a close frame. The session decides by `$closeCode` whether to reconnect
 * (3000-3499, 4000-4499) or stop (3500-3999, 4500-4999), WHITEMARKET_API.md 9. `$reason` is server text made safe by
 * {@see ServerText::printable()} (128 bytes of printable ASCII); the message names the code only.
 *
 * It is not a {@see \WhiteMarket\Sdk\Errors\TransportException}: that class is final (public API), so the session
 * catches both (docs/plans/2026-11-01-live-plan.md, L3 deviation).
 *
 * @internal
 */
final class ServerClosed extends WhiteMarketException
{
    public readonly string $reason;

    public function __construct(public readonly int $closeCode, string $reason = '')
    {
        parent::__construct(\sprintf('The server closed the live connection with code %d.', $closeCode));
        $this->reason = ServerText::printable($reason);
    }
}
