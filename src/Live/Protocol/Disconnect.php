<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

/**
 * The server ends the connection (`disconnect` push). `reason` is server-controlled text cut to 128 bytes of printable ASCII.
 *
 * @internal
 */
final readonly class Disconnect
{
    public function __construct(
        public int $code,
        public string $reason,
    ) {}
}
