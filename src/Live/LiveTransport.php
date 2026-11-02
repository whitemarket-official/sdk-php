<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

/**
 * Which transport carries live updates: a WebSocket (the default) or Server-Sent Events.
 */
enum LiveTransport: string
{
    case WebSocket = 'websocket';
    case Sse = 'sse';
}
