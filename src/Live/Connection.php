<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\TransportException;

/**
 * One live connection to the server (a WebSocket or an SSE stream). It knows the wire framing only; the session owns
 * reconnects, subscriptions, deadlines and token handling.
 *
 * Neither the token nor the connection URL may appear in an exception message, a log line or a chained exception.
 *
 * @internal
 */
interface Connection
{
    /**
     * Connects, authenticates with the token and subscribes to the channels; returns the server's connect reply.
     * `$timeout` is the seconds left for the whole connect; the connection counts them from the call.
     *
     * @param list<string> $channels
     *
     * @throws TransportException when the connection cannot be established or the first message is not a connect reply
     * @throws ConnectRejected when the server answers the connect command with an error (WebSocket)
     */
    public function open(#[\SensitiveParameter] Secret $token, array $channels, float $timeout): ConnectInfo;

    /**
     * One raw Centrifugo message (one JSON document, WebSocket frames are already split on "\n"), or null when
     * `$timeout` seconds pass without a complete message.
     *
     * @throws TransportException when the connection broke or a message exceeds the size cap
     * @throws ServerClosed when a WebSocket server sent a close frame (the code decides reconnect or stop)
     */
    public function read(float $timeout): ?string;

    /**
     * Whether a channel can be added on an open connection (WebSocket) or only at connect time (SSE). When false the
     * session reconnects with the new channel list instead of calling {@see subscribe()}.
     */
    public function subscribesAfterConnect(): bool;

    /**
     * Subscribes to a channel on an open connection; only called when {@see subscribesAfterConnect()} is true.
     *
     * @throws TransportException when the command cannot be sent
     */
    public function subscribe(string $channel): void;

    /**
     * Answers a server ping (`{}`) when the connect reply asked for it.
     */
    public function pong(): void;

    /**
     * Closes the connection; safe to call on a closed or never opened one.
     */
    public function close(): void;
}
