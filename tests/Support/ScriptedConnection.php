<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\Connection;

/**
 * A {@see Connection} that plays a script: one queue of `open()` outcomes and one queue of `read()` outcomes shared by
 * all connections of a session. Time passes only through the {@see ManualClock}: an idle read (`null`) moves it to the
 * timeout the session passed, `[seconds, message]` moves it by `seconds` first. An exhausted script fails the test
 * instead of blocking.
 */
final class ScriptedConnection implements Connection
{
    /** @var list<array{token: string, channels: list<string>, deadline: float}> */
    private array $opens = [];

    /** @var list<float> absolute deadlines of the reads */
    private array $readDeadlines = [];

    /** @var list<string> */
    private array $subscribed = [];

    private int $pongs = 0;

    private int $closes = 0;

    private bool $subscribeAfterConnect = true;

    private ?\Throwable $subscribeError = null;

    /**
     * @param list<ConnectInfo|\Throwable> $openScript
     * @param list<string|\Throwable|array{float, string|\Throwable}|null> $reads a message, an exception to throw, `[seconds, message-or-exception]`, or null for "nothing until the deadline"
     */
    public function __construct(
        private readonly ManualClock $clock,
        private array $openScript,
        private array $reads = [],
    ) {}

    /**
     * @return list<array{token: string, channels: list<string>, deadline: float}>
     */
    public function opens(): array
    {
        return $this->opens;
    }

    /**
     * @return array{token: string, channels: list<string>, deadline: float}
     */
    public function openAt(int $index): array
    {
        return $this->opens[$index] ?? throw new \OutOfBoundsException('no open call #' . $index);
    }

    /**
     * The absolute deadline (clock now plus the timeout) the session passed to read call number `$index`.
     */
    public function deadlineAt(int $index): float
    {
        return $this->readDeadlines[$index] ?? throw new \OutOfBoundsException('no read call #' . $index);
    }

    /**
     * @return list<string>
     */
    public function subscribed(): array
    {
        return $this->subscribed;
    }

    public function pongs(): int
    {
        return $this->pongs;
    }

    public function closes(): int
    {
        return $this->closes;
    }

    /**
     * Makes the connection behave like SSE, which subscribes at connect time only.
     */
    public function failSubscribe(): void
    {
        $this->subscribeAfterConnect = false;
    }

    /**
     * Makes `subscribe()` throw `$error`.
     */
    public function throwOnSubscribe(\Throwable $error): void
    {
        $this->subscribeError = $error;
    }

    /**
     * @param list<string|\Throwable|array{float, string|\Throwable}|null> $reads
     */
    public function queueReads(array $reads): void
    {
        $this->reads = [...$this->reads, ...$reads];
    }

    public function open(#[\SensitiveParameter] Secret $token, array $channels, float $timeout): ConnectInfo
    {
        $this->opens[] = ['token' => $token->reveal(), 'channels' => $channels, 'deadline' => $this->now() + $timeout];
        $step = array_shift($this->openScript) ?? throw new \LogicException('open script exhausted');

        return $step instanceof \Throwable ? throw $step : $step;
    }

    public function read(float $timeout): ?string
    {
        $this->readDeadlines[] = $this->now() + $timeout;
        if ($this->reads === []) {
            throw new \LogicException('read script exhausted');
        }
        $step = array_shift($this->reads);
        if ($step instanceof \Throwable) {
            throw $step;
        }
        if (\is_array($step)) {
            $this->clock->advance($step[0]);
            if ($step[1] instanceof \Throwable) {
                throw $step[1];
            }

            return $step[1];
        }
        if ($step === null) {
            $this->clock->advance(max(0.0, $timeout));
        }

        return $step;
    }

    public function subscribesAfterConnect(): bool
    {
        return $this->subscribeAfterConnect;
    }

    public function subscribe(string $channel): void
    {
        if ($this->subscribeError instanceof \Throwable) {
            throw $this->subscribeError;
        }
        $this->subscribed[] = $channel;
    }

    private function now(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }

    public function pong(): void
    {
        ++$this->pongs;
    }

    public function close(): void
    {
        ++$this->closes;
    }
}
