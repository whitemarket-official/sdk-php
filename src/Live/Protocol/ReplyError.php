<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

/**
 * The `error` of a failed command reply. `message` is server-controlled text cut to 128 bytes of printable ASCII.
 *
 * @internal
 */
final readonly class ReplyError
{
    public function __construct(
        public int $code,
        public string $message,
        public bool $temporary,
    ) {}
}
