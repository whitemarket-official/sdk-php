<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

/**
 * The server removed the client from a channel (`unsubscribe` push); codes from 2500 allow resubscribing.
 *
 * @internal
 */
final readonly class Unsubscribe
{
    public function __construct(
        public string $channel,
        public int $code,
    ) {}
}
