<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

/**
 * The reply to `connect` (a WS reply with the command id, or the first SSE event). `ping` and `ttl` are seconds;
 * `subs` are the channels the server subscribed the client to.
 *
 * @internal
 */
final readonly class ConnectReply
{
    /**
     * @param list<string> $subs
     */
    public function __construct(
        public ?int $ping,
        public bool $pong,
        public ?int $ttl,
        public bool $expires,
        public array $subs,
    ) {}
}
