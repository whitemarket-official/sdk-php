<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

/**
 * The reply to a client command (`{"id":2,"subscribe":{}}`, `{"id":2,"error":{...}}`). `error` is set when the
 * server rejected the command; the payload of a success is not kept. A reply to `connect` is a
 * {@see ConnectReply} instead.
 *
 * @internal
 */
final readonly class Reply
{
    public function __construct(
        public int $id,
        public ?ReplyError $error,
    ) {}
}
