<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use Psr\Log\LoggerInterface;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\TokenProvider;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\LiveConnectionException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\LiveDisconnected;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\Live\Protocol\Disconnect;
use WhiteMarket\Sdk\Live\Protocol\MessageDecoder;
use WhiteMarket\Sdk\Live\Protocol\Ping;
use WhiteMarket\Sdk\Live\Protocol\Publication;
use WhiteMarket\Sdk\Live\Protocol\Reply;
use WhiteMarket\Sdk\Live\Protocol\ReplyError;
use WhiteMarket\Sdk\Live\Protocol\Unsubscribe;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\RandomSource;
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\Transport\SafeLogger;
use WhiteMarket\Sdk\Transport\Sleeper;
use WhiteMarket\Sdk\Transport\SystemRandomSource;

/**
 * One live session (SDK_SPEC 3.11, design "Session rules"): owns a {@see Connection}, the channel set, reconnects,
 * deadlines and token handling, decodes and maps messages and dispatches them to the {@see LiveHandlers}.
 *
 * Synchronous: all work happens inside {@see poll()}, {@see connect()}, {@see events()} and {@see run()}. Time is
 * seconds on the injected {@see Clock}; the session counts from its own creation instant so float precision holds, and
 * hands the connection the seconds left.
 *
 * - Reconnect: full jitter `random * min(20, 0.5 * 2^(attempt-1))`, the attempt counter resets after a connection
 *   stayed up 60 s. Close/disconnect codes 3000-3499 and 4000-4499 and network errors reconnect; 3500-3999 and
 *   4500-4999 are terminal ({@see LiveConnectionException}); any other code (WebSocket 1000, 1001, ...) reconnects too,
 *   but at most {@see MAX_OTHER_CLOSES} times in a row, the next one is terminal (the count resets only after a
 *   connection stayed up 60 s). Close reasons are server text (printable ASCII, cut to 128 bytes), not redacted.
 * - TLS only: the base URL is https-only, so live runs over wss (WebSocket) or https (SSE).
 * - `Retry-After` can lengthen a backoff, never shorten it. A reconnect a server message asks for (SSE resubscribe, the
 *   scheduled token refresh) is immediate only after a connection that stayed up 60 s, otherwise it is a counted backoff.
 * - Token: 3500 and reply error 109 (and an SSE 401) refresh once and reconnect at once; the second in a row is a
 *   terminal {@see AuthenticationException}. 3005, a connect reply `ttl` of 10 minutes or less and the scheduled
 *   refresh (ttl above 10 minutes) share ONE refresh per streak (a streak ends when a connection stayed up 60 s); once
 *   it is used, 3005 still reconnects with backoff but without a refresh, and a due scheduled refresh waits. So the
 *   worst case is two `refresh()` calls per streak: one of these and one auth rejection. A provider that cannot renew
 *   does not loop. A server ping interval is clamped to {@see ConnectInfo::MAX_PING_SECONDS}.
 * - Idle deadline: `ping + 10 s`, reset by any message.
 * - Handler exceptions propagate out of the calling method; the connection stays open.
 *
 * Logs connect and disconnect at info with the code and the attempt number only.
 *
 * @internal
 */
final class LiveSession
{
    private const BACKOFF_BASE = 0.5;
    private const BACKOFF_CAP = 20.0;
    private const STABLE_AFTER = 60.0;

    /** At most this many consecutive closes with a code outside the Centrifugo ranges reconnect; the next one is terminal. */
    public const MAX_OTHER_CLOSES = 5;
    private const REFRESH_BELOW = 600.0;
    private const MAX_RETRY_AFTER = 300;
    private const EPSILON = 1e-6;
    private const AUTH_EXPIRED_CODE = 109;
    private const ALREADY_SUBSCRIBED_CODE = 105;
    private const TOKEN_INVALID_CLOSE = 3500;
    private const TOKEN_EXPIRED_CLOSE = 3005;
    private const RESUBSCRIBE_FROM = 2500;

    private readonly LoggerInterface $logger;

    private readonly RetryPolicy $backoff;

    /** Whole seconds of the creation instant; session time is relative to it. */
    private readonly int $epoch;

    private ?Connection $connection = null;

    /** @var list<string> */
    private array $channels = [];

    private bool $closed = false;

    private bool $connected = false;

    private bool $everConnected = false;

    private bool $forceRefresh = false;

    private bool $authRetried = false;

    private bool $tokenRefreshUsedInStreak = false;

    private bool $answerPings = false;

    private float $idleSeconds = ConnectInfo::DEFAULT_PING_SECONDS + ConnectInfo::IDLE_GRACE_SECONDS;

    private int $attempt = 0;

    /** Consecutive closes with a code outside the Centrifugo ranges; reset by a connection that stayed up 60 s. */
    private int $otherCloses = 0;

    private float $nextAttemptAt = 0.0;

    private float $connectedAt = 0.0;

    private float $idleDeadline = 0.0;

    private ?float $refreshAt = null;

    /**
     * @param \Closure(): Connection $connectionFactory called once, on the first connect
     */
    public function __construct(
        private readonly TokenProvider $tokens,
        #[\SensitiveParameter]
        private readonly \Closure $connectionFactory,
        private readonly LiveOptions $options,
        LoggerInterface $logger,
        private readonly Clock $clock,
        private readonly Sleeper $sleeper,
        private readonly LiveHandlers $handlers,
        private readonly RandomSource $random = new SystemRandomSource(),
    ) {
        $this->logger = SafeLogger::wrap($logger);
        $this->backoff = new RetryPolicy(maxAttempts: 1, baseDelaySeconds: self::BACKOFF_BASE, factor: 2.0, maxDelaySeconds: self::BACKOFF_CAP);
        $this->epoch = $this->clock->now()->getTimestamp();
    }

    /**
     * Adds a channel to the subscription set. On an open WebSocket it is subscribed at once, on SSE the session
     * reconnects with the new channel list.
     */
    public function subscribe(string $channel): void
    {
        if ($this->closed || \in_array($channel, $this->channels, true)) {
            return;
        }
        $this->channels[] = $channel;
        if (!$this->connected) {
            return;
        }

        $this->subscribeOrReconnect($channel, 'subscriptions changed', true);
    }

    /**
     * Connects now and returns once connected, waiting out backoffs.
     *
     * @throws LiveConnectionException|HttpException a terminal failure
     */
    public function connect(): void
    {
        while (!$this->closed && !$this->connected) {
            $this->attemptConnect(\INF);
        }
    }

    /**
     * The next event, after its handlers ran, or null when `$timeout` seconds pass without one (null: no limit) or
     * the session is closed. Connects and reconnects as needed.
     *
     * Throws {@see LiveConnectionException} or an {@see HttpException} ({@see AuthenticationException} after a second
     * token rejection) on a terminal failure; not declared with `@throws` because {@see events()} yields over it.
     */
    public function poll(?float $timeout = null): ?LiveEvent
    {
        $until = $timeout === null ? \INF : $this->now() + $timeout;
        while (!$this->closed) {
            if (!$this->connected) {
                $this->attemptConnect($until);
                if (!$this->connected && $this->reached($until)) {
                    return null;
                }

                continue;
            }

            if ($this->refreshAt !== null && $this->reached($this->refreshAt)) {
                $this->scheduledRefresh();

                continue;
            }
            if ($this->reached($this->idleDeadline)) {
                $this->reconnectAfterBackoff();

                continue;
            }

            $event = $this->readOnce(min($until, $this->idleDeadline, $this->refreshAt ?? \INF));
            if ($event instanceof LiveEvent) {
                return $event;
            }
            if ($this->reached($until)) {
                return null;
            }
        }

        return null;
    }

    /**
     * Every event, blocking, until {@see close()}; reconnects as needed.
     *
     * @return \Generator<int, LiveEvent, mixed, void>
     */
    public function events(): \Generator
    {
        while (!$this->closed) {
            $event = $this->poll();
            if ($event instanceof LiveEvent) {
                yield $event;
            }
        }
    }

    /**
     * Dispatches events to the handlers for `$duration` seconds (null: until {@see close()}).
     */
    public function run(?float $duration = null): void
    {
        $until = $duration === null ? \INF : $this->now() + $duration;
        while (!$this->closed && !$this->reached($until)) {
            $this->poll($until === \INF ? null : max(0.0, $until - $this->now()));
        }
    }

    /**
     * Idempotent; callable from a handler.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $wasConnected = $this->endConnection();
        $this->logger->info('WhiteMarket live: closed', ['attempt' => $this->attempt]);
        if ($wasConnected) {
            $this->handlers->disconnected(new LiveDisconnected(null, '', false));
        }
    }

    private function attemptConnect(float $until): void
    {
        $now = $this->now();
        if (!$this->reached($this->nextAttemptAt)) {
            $wait = min($this->nextAttemptAt - $now, $until - $now);
            if ($wait > 0) {
                $this->sleeper->sleep($wait);
            }

            return;
        }

        $this->handlers->connecting();
        if ($this->closed) {
            return;
        }

        try {
            $token = $this->forceRefresh ? $this->tokens->refresh() : $this->tokens->accessToken();
            $this->forceRefresh = false;
        } catch (AuthenticationException $e) {
            $this->terminate($this->endConnection(), $e, null, '');
        } catch (HttpException $e) {
            $this->handleHttpFailure($e, false);

            return;
        } catch (WhiteMarketException $e) {
            $this->handleFailedAttempt($e);

            return;
        }

        $this->open($token);
    }

    private function open(#[\SensitiveParameter] Secret $token): void
    {
        try {
            $info = $this->connection()->open($token, $this->channels, $this->options->connectTimeout);
        } catch (ConnectRejected $e) {
            $this->handleConnectRejected($e);

            return;
        } catch (ServerClosed $e) {
            $this->handleCloseCode($e->closeCode, $e->reason);

            return;
        } catch (HttpException $e) {
            $this->handleHttpFailure($e, true);

            return;
        } catch (TransportException $e) {
            $this->handleFailedAttempt($e);

            return;
        }

        $this->established($info);
    }

    private function established(ConnectInfo $info): void
    {
        $now = $this->now();
        $ttl = $info->ttl;
        $shortLived = $ttl !== null && $ttl <= self::REFRESH_BELOW;
        if ($shortLived && !$this->tokenRefreshUsedInStreak) {
            $this->tokenRefreshUsedInStreak = true;
            $this->reconnectNow('token refresh', true);

            return;
        }
        // A short-lived token that was refreshed once in this streak already: the provider cannot renew, keep this
        // connection and let the server end it at expiry. The streak ends only after a connection stayed up STABLE_AFTER.

        $this->connected = true;
        $this->connectedAt = $now;
        $this->idleDeadline = $now + $info->idleSeconds();
        $this->refreshAt = $ttl === null || $shortLived ? null : $now + $ttl - self::REFRESH_BELOW;
        $this->answerPings = $info->pong;
        $this->idleSeconds = $info->idleSeconds();
        $this->nextAttemptAt = 0.0;
        $this->logger->info('WhiteMarket live: connected', ['attempt' => $this->attempt]);
        $reconnected = $this->everConnected;
        $this->everConnected = true;
        $this->handlers->connected(new LiveConnected($reconnected));
    }

    private function readOnce(float $deadline): ?LiveEvent
    {
        try {
            $raw = $this->connection()->read(max(0.0, $deadline - $this->now()));
        } catch (ServerClosed $e) {
            $this->handleCloseCode($e->closeCode, $e->reason);

            return null;
        } catch (TransportException) {
            $this->reconnectAfterBackoff();

            return null;
        }
        if ($raw === null) {
            return null;
        }
        $this->idleDeadline = $this->now() + $this->idleSeconds;

        return $this->process($raw);
    }

    private function process(#[\SensitiveParameter] string $raw): ?LiveEvent
    {
        try {
            $message = MessageDecoder::decode($raw);
        } catch (DecodeException $e) {
            $this->handlers->error($e);

            return null;
        }

        if ($message instanceof Publication) {
            $mapped = EventMapper::map($message);
            if ($mapped->error instanceof DecodeException) {
                $this->handlers->error($mapped->error);
            }
            $this->handlers->event($mapped->event, $message->channel);

            return $mapped->event;
        }
        if ($message instanceof Ping) {
            $this->answerPing();
        } elseif ($message instanceof Reply) {
            $this->handleReply($message);
        } elseif ($message instanceof Disconnect) {
            $this->handleCloseCode($message->code, $message->reason);
        } elseif ($message instanceof Unsubscribe) {
            $this->handleUnsubscribe($message);
        }
        // A connect reply in mid-stream and a message kind we do not model are ignored (rule 5).

        return null;
    }

    private function answerPing(): void
    {
        if (!$this->answerPings) {
            return;
        }

        try {
            $this->connection()->pong();
        } catch (TransportException) {
            $this->reconnectAfterBackoff();
        }
    }

    private function handleReply(Reply $reply): void
    {
        $error = $reply->error;
        if (!$error instanceof ReplyError || $error->code === self::ALREADY_SUBSCRIBED_CODE) {
            return; // a success, or "already subscribed", which counts as subscribed
        }
        if ($error->code === self::AUTH_EXPIRED_CODE) {
            $this->handleAuthRejection($error->code, $error->message, null);

            return;
        }
        $this->handlers->error(new LiveConnectionException($error->code, $error->message));
    }

    /**
     * Subscribes on the open connection; a connection that subscribes at connect time only (SSE) makes the
     * session reconnect with the new channel list.
     */
    private function subscribeOrReconnect(string $channel, string $reason, bool $userTriggered): void
    {
        $connection = $this->connection();
        if (!$connection->subscribesAfterConnect()) {
            $userTriggered ? $this->reconnectNow($reason, false) : $this->reconnectServerTriggered($reason, false);

            return;
        }

        try {
            $connection->subscribe($channel);
        } catch (TransportException) {
            $this->reconnectAfterBackoff();
        }
    }

    private function handleUnsubscribe(Unsubscribe $message): void
    {
        if ($message->code >= self::RESUBSCRIBE_FROM) {
            $this->subscribeOrReconnect($message->channel, 'resubscribe', false);

            return;
        }
        $this->channels = array_values(array_filter($this->channels, static fn(string $channel): bool => $channel !== $message->channel));
        $this->handlers->error(new LiveConnectionException($message->code, 'unsubscribed by the server'));
    }

    /**
     * A close frame or a `disconnect` push: reconnect, refresh the token or stop, by the code (design rule 5).
     */
    private function handleCloseCode(int $code, string $reason): void
    {
        if ($code === self::TOKEN_INVALID_CLOSE) {
            $this->handleAuthRejection($code, $reason, null);
        } elseif (($code >= 3000 && $code <= 3499) || ($code >= 4000 && $code <= 4499)) {
            if ($code === self::TOKEN_EXPIRED_CLOSE && !$this->tokenRefreshUsedInStreak) {
                $this->tokenRefreshUsedInStreak = true;
                $this->forceRefresh = true;
            }
            $this->reconnectAfterBackoff($code, $reason);
        } elseif (($code >= 3500 && $code <= 3999) || ($code >= 4500 && $code <= 4999)) {
            $this->terminate($this->endConnection(), new LiveConnectionException($code, $reason), $code, $reason);
        } else {
            $this->reconnectAfterOtherClose($code, $reason);
        }
    }

    /**
     * A code outside the Centrifugo ranges (WebSocket 1000, 1001, ...) reconnects with the backoff, but only
     * MAX_OTHER_CLOSES times in a row; only a connection that stayed up STABLE_AFTER resets the count.
     */
    private function reconnectAfterOtherClose(int $code, string $reason): void
    {
        $wasConnected = $this->endConnection();
        if (++$this->otherCloses > self::MAX_OTHER_CLOSES) {
            $this->terminate($wasConnected, new LiveConnectionException($code, $reason), $code, $reason);
        }
        $this->scheduleReconnectAfterBackoff($wasConnected, $code, $reason, null);
    }

    private function handleConnectRejected(ConnectRejected $e): void
    {
        if ($e->errorCode === self::AUTH_EXPIRED_CODE) {
            $this->handleAuthRejection($e->errorCode, '', null);
        } elseif ($e->temporary) {
            $this->handleFailedAttempt($e);
        } else {
            $this->terminate($this->endConnection(), new LiveConnectionException($e->errorCode), $e->errorCode, '');
        }
    }

    /**
     * An HTTP status from the SSE request (or from the token provider when `$fromStream` is false): 401 refreshes once
     * (a provider's 401 is final), 429 and 503 wait `Retry-After` (at most 5 minutes), 5xx reconnect, other 4xx stop.
     *
     * `$fromStream` stays: the SDK's own providers raise a 401 as {@see AuthenticationException}, which the caller catches
     * before this method, but {@see HttpException} subclasses have a public constructor, so a custom provider can throw
     * a 401 of another subclass; refreshing again for it would loop on a provider whose 401 is final.
     */
    private function handleHttpFailure(#[\SensitiveParameter] HttpException $e, bool $fromStream): void
    {
        if ($e->status === 401 && $fromStream) {
            $this->handleAuthRejection(401, '', $e);
        } elseif ($e->status === 429 || ($e->status >= 500 && $e->status <= 599)) {
            $this->handleFailedAttempt($e, $e->retryAfter);
        } else {
            $this->terminate($this->endConnection(), $e, null, '');
        }
    }

    private function handleFailedAttempt(\Throwable $e, ?int $retryAfter = null): void
    {
        $this->reconnectAfterBackoff(minWait: $retryAfter === null ? null : (float) min($retryAfter, self::MAX_RETRY_AFTER));
        $this->handlers->error($e);
    }

    private function handleAuthRejection(int $code, string $reason, #[\SensitiveParameter] ?HttpException $original): void
    {
        $wasConnected = $this->endConnection();
        if ($this->authRetried) {
            $this->terminate($wasConnected, $original instanceof AuthenticationException ? $original : new AuthenticationException(401, ''), $code, $reason);
        }
        $this->authRetried = true;
        $this->forceRefresh = true;
        $this->scheduleReconnectNow($wasConnected, $code, $reason);
    }

    private function reconnectNow(string $reason, bool $refresh): void
    {
        $wasConnected = $this->endConnection();
        $this->forceRefresh = $this->forceRefresh || $refresh;
        $this->scheduleReconnectNow($wasConnected, null, $reason);
    }

    /**
     * The scheduled token refresh is due. A ttl-driven refresh already done in this streak (no connection stayed up
     * STABLE_AFTER since) defers it until this connection has been up STABLE_AFTER, so a server that always reports a
     * near ttl costs one refresh per streak, not one per backoff cycle.
     */
    private function scheduledRefresh(): void
    {
        if ($this->tokenRefreshUsedInStreak && !$this->connectedLongEnough()) {
            $this->refreshAt = $this->connectedAt + self::STABLE_AFTER;

            return;
        }
        $this->reconnectServerTriggered('token refresh', true);
        $this->tokenRefreshUsedInStreak = true;
    }

    /**
     * A reconnect that a server message asks for (resubscribe, scheduled token refresh): immediate only when the
     * connection being left stayed up STABLE_AFTER, otherwise a counted attempt with the jittered backoff, so a server
     * cannot make the session spin.
     */
    private function reconnectServerTriggered(string $reason, bool $refresh): void
    {
        if ($this->connected && $this->connectedLongEnough()) {
            $this->reconnectNow($reason, $refresh);

            return;
        }
        $this->forceRefresh = $this->forceRefresh || $refresh;
        $this->reconnectAfterBackoff(null, $reason);
    }

    /**
     * @param ?float $minWait a server-sent `Retry-After`: it can lengthen the backoff, never shorten it
     */
    private function reconnectAfterBackoff(?int $code = null, string $reason = '', ?float $minWait = null): void
    {
        $this->scheduleReconnectAfterBackoff($this->endConnection(), $code, $reason, $minWait);
    }

    private function scheduleReconnectNow(bool $wasConnected, ?int $code, string $reason): void
    {
        $this->nextAttemptAt = $this->now();
        $this->announceDisconnect($wasConnected, $code, $reason);
    }

    private function scheduleReconnectAfterBackoff(bool $wasConnected, ?int $code, string $reason, ?float $minWait): void
    {
        ++$this->attempt;
        $max = $this->options->maxReconnectAttempts;
        if ($max !== null && $this->attempt > $max) {
            $this->terminate($wasConnected, new LiveConnectionException($code, 'reconnect attempts exhausted'), $code, $reason);
        }
        $this->nextAttemptAt = $this->now() + max($minWait ?? 0.0, $this->backoff->delay($this->attempt, $this->random));
        $this->announceDisconnect($wasConnected, $code, $reason);
    }

    private function announceDisconnect(bool $wasConnected, ?int $code, string $reason): void
    {
        $this->logger->info('WhiteMarket live: disconnected', ['code' => $code, 'attempt' => $this->attempt]);
        if ($wasConnected) {
            $this->handlers->disconnected(new LiveDisconnected($code, $reason, true));
        }
    }

    private function terminate(bool $wasConnected, \Throwable $e, ?int $code, string $reason): never
    {
        $this->closed = true;
        $this->logger->info('WhiteMarket live: stopped', ['code' => $code, 'attempt' => $this->attempt]);
        if ($wasConnected) {
            $this->handlers->disconnected(new LiveDisconnected($code, $reason, false));
        }

        throw $e;
    }

    /**
     * Releases the connection; true when it had been announced as connected. A connection that stayed up 60 s resets the
     * attempt counter and the token retry.
     */
    private function endConnection(): bool
    {
        $wasConnected = $this->connected;
        if ($wasConnected && $this->connectedLongEnough()) {
            $this->attempt = 0;
            $this->authRetried = false;
            $this->tokenRefreshUsedInStreak = false;
            $this->otherCloses = 0;
        }
        $this->connected = false;
        $this->refreshAt = null;
        $this->connection?->close();

        return $wasConnected;
    }

    private function connection(): Connection
    {
        return $this->connection ??= ($this->connectionFactory)();
    }

    private function now(): float
    {
        $time = $this->clock->now();

        return (float) ($time->getTimestamp() - $this->epoch) + (float) $time->format('u') / 1_000_000;
    }

    /**
     * The one "up STABLE_AFTER" decision, with the same tolerance as every other deadline ({@see reached()}).
     */
    private function connectedLongEnough(): bool
    {
        return $this->reached($this->connectedAt + self::STABLE_AFTER);
    }

    private function reached(float $time): bool
    {
        return $this->now() >= $time - self::EPSILON;
    }
}
