<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\LiveConnectionException;
use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\LiveDisconnected;
use WhiteMarket\Sdk\Live\Event\LiveEvent;

/**
 * A live session (SDK_SPEC 3.11), obtained from {@see \WhiteMarket\Sdk\WhiteMarketClient::live()}; one connection per
 * instance. Live events are hints: decide on GraphQL state after one.
 *
 * Synchronous and blocking: nothing happens between calls. Handlers run inside {@see poll()}, {@see events()} and
 * {@see run()}, before the event is returned; an exception thrown by a handler propagates to that call and the
 * connection stays open, so the caller may continue or {@see close()}. {@see close()} may be called from a handler; it
 * takes effect at the next message or deadline. The connection is made on the first of {@see connect()}, {@see poll()},
 * {@see events()} and {@see run()} and kept up with reconnects (backoff, fresh token, resubscribe) until
 * {@see close()}. After a reconnect `LiveConnected::$reconnected` is true: events of the gap are lost, so re-read what
 * you care about through GraphQL. A terminal failure (a close code that does not allow reconnecting, a second token
 * rejection, `maxReconnectAttempts` used up) is thrown from the blocking call and ends the session.
 *
 * Handlers get a closure typed by the event class: `on(DealCompleted::class, fn (DealCompleted $e) => ...)`;
 * `LiveEvent::class` matches all events and `UnknownEvent::class` the ones this version does not map.
 */
final readonly class Live
{
    private const PRODUCTS_CHANNEL = 'market_products_updates';

    private const PERSONAL_PREFIX = '#';

    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient::live()}
     */
    public function __construct(
        #[\SensitiveParameter]
        private LiveSession $session,
        private LiveHandlers $handlers,
    ) {}

    /**
     * Subscribes to the product feed `market_products_updates` (added, edited, removed). The optional handler gets the
     * events of that channel only. On an open WebSocket the subscription is sent at once, on SSE the session
     * reconnects with the new channel list.
     *
     * @param (\Closure(LiveEvent): void)|null $handler
     */
    public function subscribeProducts(?\Closure $handler = null): void
    {
        $this->session->subscribe(self::PRODUCTS_CHANNEL);
        if ($handler instanceof \Closure) {
            $this->handlers->onEvent($handler, static fn(string $channel): bool => $channel === self::PRODUCTS_CHANNEL);
        }
    }

    /**
     * Receives the events of the personal channel (`#<personId>`: deal events). The server subscribes it on connect, so
     * nothing is sent and no `profile_id` call is made; the handler gets the events of channels that start with `#`.
     *
     * @param (\Closure(LiveEvent): void)|null $handler
     */
    public function subscribePersonal(?\Closure $handler = null): void
    {
        if ($handler instanceof \Closure) {
            $this->handlers->onEvent($handler, static fn(string $channel): bool => str_starts_with($channel, self::PERSONAL_PREFIX));
        }
    }

    /**
     * @template T of LiveEvent
     *
     * @param class-string<T> $type an event class or interface
     * @param \Closure(T): void $handler
     */
    public function on(string $type, \Closure $handler): void
    {
        $this->handlers->onEvent(static function (LiveEvent $event) use ($type, $handler): void {
            if ($event instanceof $type) {
                $handler($event);
            }
        });
    }

    /**
     * @param \Closure(): void $handler
     */
    public function onConnecting(\Closure $handler): void
    {
        $this->handlers->onConnecting($handler);
    }

    /**
     * @param \Closure(LiveConnected): void $handler
     */
    public function onConnected(\Closure $handler): void
    {
        $this->handlers->onConnected($handler);
    }

    /**
     * @param \Closure(LiveDisconnected): void $handler
     */
    public function onDisconnected(\Closure $handler): void
    {
        $this->handlers->onDisconnected($handler);
    }

    /**
     * Called with a non-fatal problem: a malformed message, a failed connect attempt before the next retry, a
     * rejected command, a channel the server removed.
     *
     * @param \Closure(\Throwable): void $handler
     */
    public function onError(\Closure $handler): void
    {
        $this->handlers->onError($handler);
    }

    /**
     * Connects now (waiting out backoffs) and returns once connected; optional, the blocking calls connect on demand.
     *
     * @throws LiveConnectionException|HttpException a terminal failure
     */
    public function connect(): void
    {
        $this->session->connect();
    }

    /**
     * The next event after its handlers ran, or null after `$timeout` seconds without one (or when closed).
     *
     * @throws \InvalidArgumentException a negative or NaN `$timeout`
     * @throws LiveConnectionException|HttpException a terminal failure ({@see AuthenticationException} after a second token rejection)
     */
    public function poll(float $timeout): ?LiveEvent
    {
        return $this->session->poll($this->seconds('timeout', $timeout));
    }

    /**
     * Every event, blocking, until {@see close()}.
     *
     * @return \Generator<int, LiveEvent, mixed, void>
     *
     * @throws LiveConnectionException|HttpException a terminal failure
     */
    public function events(): \Generator
    {
        return $this->session->events();
    }

    /**
     * Dispatches events to the handlers for `$duration` seconds (null: until {@see close()}).
     *
     * @throws \InvalidArgumentException a negative or NaN `$duration`
     * @throws LiveConnectionException|HttpException a terminal failure
     */
    public function run(?float $duration = null): void
    {
        $this->session->run($duration === null ? null : $this->seconds('duration', $duration));
    }

    /**
     * Closes the connection; idempotent.
     */
    public function close(): void
    {
        $this->session->close();
    }

    private function seconds(string $name, float $value): float
    {
        if (is_nan($value) || $value < 0) {
            throw new \InvalidArgumentException($name . ' must be a number of seconds, 0 or more.');
        }

        return $value;
    }
}
