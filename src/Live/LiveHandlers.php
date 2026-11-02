<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\LiveDisconnected;
use WhiteMarket\Sdk\Live\Event\LiveEvent;

/**
 * The handlers of one live session: event handlers, each optionally filtered by a channel predicate (the filter by
 * event class lives in {@see Live::on()}), and the lifecycle and error callbacks. A handler runs
 * in registration order; an exception from a handler propagates to the caller of `poll()` / `events()` / `run()` and
 * the session stays usable (design D7).
 *
 * @internal
 */
final class LiveHandlers
{
    /** @var list<array{\Closure(LiveEvent): void, (\Closure(string): bool)|null}> */
    private array $events = [];

    /** @var list<\Closure(): void> */
    private array $connecting = [];

    /** @var list<\Closure(LiveConnected): void> */
    private array $connected = [];

    /** @var list<\Closure(LiveDisconnected): void> */
    private array $disconnected = [];

    /** @var list<\Closure(\Throwable): void> */
    private array $errors = [];

    /**
     * @param \Closure(LiveEvent): void $handler
     * @param (\Closure(string): bool)|null $acceptsChannel when set, only events of channels it accepts
     */
    public function onEvent(\Closure $handler, ?\Closure $acceptsChannel = null): void
    {
        $this->events[] = [$handler, $acceptsChannel];
    }

    /**
     * @param \Closure(): void $handler
     */
    public function onConnecting(\Closure $handler): void
    {
        $this->connecting[] = $handler;
    }

    /**
     * @param \Closure(LiveConnected): void $handler
     */
    public function onConnected(\Closure $handler): void
    {
        $this->connected[] = $handler;
    }

    /**
     * @param \Closure(LiveDisconnected): void $handler
     */
    public function onDisconnected(\Closure $handler): void
    {
        $this->disconnected[] = $handler;
    }

    /**
     * @param \Closure(\Throwable): void $handler
     */
    public function onError(\Closure $handler): void
    {
        $this->errors[] = $handler;
    }

    public function event(LiveEvent $event, string $channel): void
    {
        foreach ($this->events as [$handler, $accepts]) {
            if ($accepts === null || $accepts($channel)) {
                $handler($event);
            }
        }
    }

    public function connecting(): void
    {
        foreach ($this->connecting as $handler) {
            $handler();
        }
    }

    public function connected(LiveConnected $event): void
    {
        foreach ($this->connected as $handler) {
            $handler($event);
        }
    }

    public function disconnected(LiveDisconnected $event): void
    {
        foreach ($this->disconnected as $handler) {
            $handler($event);
        }
    }

    public function error(\Throwable $error): void
    {
        foreach ($this->errors as $handler) {
            $handler($error);
        }
    }
}
