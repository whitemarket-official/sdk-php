<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

/**
 * The empty server message `{}`: a ping. Any message resets the idle timer; on WebSocket with `pong: true` the client
 * answers with `{}`.
 *
 * @internal
 */
final readonly class Ping {}
