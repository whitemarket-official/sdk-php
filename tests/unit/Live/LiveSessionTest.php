<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GeneralHttpException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\LiveConnectionException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\ConnectRejected;
use WhiteMarket\Sdk\Live\Event\DealCompleted;
use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\LiveDisconnected;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\Event\UnknownEvent;
use WhiteMarket\Sdk\Live\LiveHandlers;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveSession;
use WhiteMarket\Sdk\Live\ServerClosed;
use WhiteMarket\Sdk\Tests\Support\FixedRandomSource;
use WhiteMarket\Sdk\Tests\Support\LiveFixture;
use WhiteMarket\Sdk\Tests\Support\ManualClock;
use WhiteMarket\Sdk\Tests\Support\MemoryLogger;
use WhiteMarket\Sdk\Tests\Support\ScriptedConnection;
use WhiteMarket\Sdk\Tests\Support\ScriptedTokens;
use WhiteMarket\Sdk\Tests\Support\TokenHoldingConnection;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(LiveSession::class)]
#[CoversClass(LiveHandlers::class)]
final class LiveSessionTest extends TestCase
{
    private const START = '2026-01-01T00:00:00+00:00';

    private ManualClock $clock;

    private ScriptedTokens $tokens;

    private ScriptedConnection $connection;

    private MemoryLogger $logger;

    private LiveSession $session;

    private LiveHandlers $handlers;

    /** @var list<string> */
    private array $log = [];

    protected function setUp(): void
    {
        $this->start([]);
    }

    private function delay(int $index): float
    {
        return $this->clock->delays()[$index] ?? throw new \OutOfBoundsException('no delay #' . $index);
    }

    /**
     * @param list<ConnectInfo|\Throwable> $opens
     * @param list<string|\Throwable|array{float, string|\Throwable}|null> $reads
     */
    private function start(array $opens, array $reads = [], ?LiveOptions $options = null, float $fraction = 1.0, bool $renews = true): void
    {
        $this->clock = new ManualClock(self::START);
        $this->tokens = new ScriptedTokens($renews);
        $this->logger = new MemoryLogger();
        $this->connection = new ScriptedConnection($this->clock, $opens, $reads);
        $connection = $this->connection;
        $this->handlers = new LiveHandlers();
        $this->session = new LiveSession(
            $this->tokens,
            static fn(): ScriptedConnection => $connection,
            $options ?? new LiveOptions(),
            $this->logger,
            $this->clock,
            $this->clock,
            $this->handlers,
            new FixedRandomSource($fraction),
        );
        $this->log = [];
        $this->handlers->onConnecting(function (): void {
            $this->log[] = 'connecting';
        });
        $this->handlers->onConnected(function (LiveConnected $e): void {
            $this->log[] = 'connected:' . ($e->reconnected ? '1' : '0');
        });
        $this->handlers->onDisconnected(function (LiveDisconnected $e): void {
            $this->log[] = \sprintf('disconnected:%s:%s', $e->code ?? 'null', $e->willReconnect ? 'retry' : 'stop');
        });
        $this->handlers->onError(function (\Throwable $e): void {
            $this->log[] = 'error:' . $e::class;
        });
    }

    private static function info(int $ping = 25, bool $pong = true, ?int $ttl = 86400): ConnectInfo
    {
        return new ConnectInfo($ping, $pong, $ttl, []);
    }

    private function product(): string
    {
        return LiveFixture::raw('ws_product_added.json');
    }

    private function deal(): string
    {
        $message = json_encode(LiveFixture::message('deal_completed.json'), \JSON_THROW_ON_ERROR);

        return json_encode(['push' => ['channel' => '#42', 'pub' => ['data' => ['message' => $message]]]], \JSON_THROW_ON_ERROR);
    }

    private static function disconnect(int $code, string $reason = 'r'): string
    {
        return json_encode(['disconnect' => ['code' => $code, 'reason' => $reason]], \JSON_THROW_ON_ERROR);
    }

    private static function replyError(int $code): string
    {
        return json_encode(['id' => 3, 'error' => ['code' => $code, 'message' => 'm']], \JSON_THROW_ON_ERROR);
    }

    private function startTimestamp(): float
    {
        return (float) (new \DateTimeImmutable(self::START))->getTimestamp();
    }

    /**
     * @param list<float> $expected
     * @param list<float> $actual
     */
    private function assertDelays(array $expected, array $actual): void
    {
        self::assertCount(\count($expected), $actual, 'delays: ' . implode(',', $actual));
        foreach (array_values($actual) as $i => $delay) {
            self::assertEqualsWithDelta($expected[$i] ?? null, $delay, 1e-5);
        }
    }

    // --- reconnect backoff ---------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{float, list<float>}>
     */
    public static function backoffs(): iterable
    {
        yield 'jitter 1.0 -> the ceiling, capped at 20' => [1.0, [0.5, 1.0, 2.0, 4.0, 8.0, 16.0, 20.0, 20.0]];
        yield 'jitter 0.5 -> half of the ceiling' => [0.5, [0.25, 0.5, 1.0, 2.0, 4.0, 8.0, 10.0, 10.0]];
    }

    /**
     * @param list<float> $expected
     */
    #[Test]
    #[DataProvider('backoffs')]
    public function failedConnectsWaitFullJitterBackoffCappedAtTwentySeconds(float $fraction, array $expected): void
    {
        $this->start(
            [...array_fill(0, 8, new TransportException('x')), self::info()],
            [$this->product()],
            fraction: $fraction,
        );

        $event = $this->session->poll(1000.0);

        self::assertInstanceOf(ProductAdded::class, $event);
        $this->assertDelays($expected, $this->clock->delays());
        self::assertSame(array_fill(0, 8, 'error:' . TransportException::class), \array_slice(array_values(array_filter($this->log, static fn(string $l): bool => str_starts_with($l, 'error:'))), 0, 8));
    }

    #[Test]
    public function aCapOnReconnectAttemptsEndsTheSessionAtTheFirstAttemptOverTheCap(): void
    {
        $this->start(array_fill(0, 3, new TransportException('x')), options: new LiveOptions(maxReconnectAttempts: 2));

        try {
            $this->session->poll(1000.0);
            self::fail('expected the attempts to run out');
        } catch (LiveConnectionException $e) {
            self::assertNull($e->closeCode);
            self::assertSame('reconnect attempts exhausted', $e->reason);
        }
        self::assertCount(2, $this->clock->delays());
        self::assertNull($this->session->poll(1.0));
    }

    #[Test]
    public function aCapOfTwoAllowsTheThirdAttemptToSucceed(): void
    {
        $this->start(
            [new TransportException('x'), new TransportException('x'), self::info()],
            [$this->product()],
            new LiveOptions(maxReconnectAttempts: 2),
        );

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));
    }

    /**
     * @return iterable<string, array{float, float}>
     */
    public static function uptimes(): iterable
    {
        yield 'up 59.5 s keeps the attempt counter' => [59.5, 2.0];
        yield 'up 60 s resets it' => [60.0, 0.5];
    }

    #[Test]
    #[DataProvider('uptimes')]
    public function theAttemptCounterResetsAfterTheConnectionStayedUpSixtySeconds(float $uptime, float $expectedDelay): void
    {
        $this->start(
            [new TransportException('x'), new TransportException('x'), self::info(), self::info()],
            [[$uptime, $this->product()], new ServerClosed(3000), $this->product()],
        );

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));
        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));

        self::assertEqualsWithDelta($expectedDelay, $this->delay(2), 1e-5);
    }

    // --- deadlines -----------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, float}>
     */
    public static function pings(): iterable
    {
        yield 'ping 25 -> 35 s' => [25, 35.0];
        yield 'ping 10 -> 20 s' => [10, 20.0];
    }

    #[Test]
    #[DataProvider('pings')]
    public function theIdleDeadlineIsThePingIntervalPlusTenSecondsAndTheSessionReconnectsWhenItPasses(int $ping, float $expected): void
    {
        $this->start([self::info($ping), self::info($ping)], [null, $this->product()]);

        $event = $this->session->poll(1000.0);

        self::assertInstanceOf(ProductAdded::class, $event);
        self::assertEqualsWithDelta($this->startTimestamp() + $expected, $this->connection->deadlineAt(0), 1e-3);
        self::assertCount(2, $this->connection->opens());
        self::assertSame(['connecting', 'connected:0', 'disconnected:null:retry', 'connecting', 'connected:1'], $this->log);
    }

    #[Test]
    public function anyMessageMovesTheIdleDeadline(): void
    {
        $this->start([self::info(), self::info()], [[30.0, '{}'], null, $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(200.0));

        self::assertEqualsWithDelta($this->startTimestamp() + 65.0, $this->connection->deadlineAt(1) + 0.0, 1e-3);
        self::assertCount(2, $this->connection->opens());
    }

    #[Test]
    public function pollReturnsNullAfterItsTimeoutAndKeepsTheConnection(): void
    {
        $this->start([self::info()], [null]);

        self::assertNull($this->session->poll(5.0));

        self::assertEqualsWithDelta($this->startTimestamp() + 5.0, $this->connection->deadlineAt(0), 1e-3);
        self::assertCount(1, $this->connection->opens());
        self::assertSame(0, $this->connection->closes());
    }

    #[Test]
    public function pollWaitsOutABackoffInPartsOfItsTimeout(): void
    {
        $this->start([new TransportException('x'), self::info()], [$this->product()]);

        self::assertNull($this->session->poll(0.2));
        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1.0));

        $this->assertDelays([0.2, 0.3], $this->clock->delays());
    }

    // --- token ---------------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{?int, bool}>
     */
    public static function ttls(): iterable
    {
        yield 'ttl 0 -> refresh' => [0, true];
        yield 'ttl 599 -> refresh' => [599, true];
        yield 'ttl 600 (boundary) -> refresh' => [600, true];
        yield 'ttl 601 -> no refresh' => [601, false];
        yield 'no ttl (token does not expire) -> no refresh' => [null, false];
    }

    #[Test]
    #[DataProvider('ttls')]
    public function aShortTtlInTheConnectReplyRefreshesTheTokenAndReconnects(?int $ttl, bool $refreshes): void
    {
        $this->start([self::info(ttl: $ttl), self::info()], [$this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame($refreshes ? 1 : 0, $this->tokens->refreshCalls());
        self::assertCount($refreshes ? 2 : 1, $this->connection->opens());
        self::assertSame([], $this->clock->delays());
        self::assertCount(1, array_filter($this->log, static fn(string $l): bool => str_starts_with($l, 'connected:')));
        self::assertContains('connected:0', $this->log);
        if ($refreshes) {
            self::assertNotSame($this->connection->openAt(0)['token'], $this->connection->openAt(1)['token']);
        }
    }

    #[Test]
    public function aProviderThatCannotRenewDoesNotLoop(): void
    {
        $this->start([self::info(ttl: 100), self::info(ttl: 100)], [$this->product()], renews: false);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame(1, $this->tokens->refreshCalls());
        self::assertCount(2, $this->connection->opens());
    }

    #[Test]
    public function aLongConnectionRefreshesTenMinutesBeforeTheTokenExpires(): void
    {
        $this->start([self::info(ping: 1000, ttl: 1000), self::info()], [null, $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll());

        self::assertEqualsWithDelta($this->startTimestamp() + 400.0, $this->connection->deadlineAt(0), 1e-3);
        self::assertSame(1, $this->tokens->refreshCalls());
        self::assertSame(['connecting', 'connected:0', 'disconnected:null:retry', 'connecting', 'connected:1'], $this->log);
        self::assertSame([], $this->clock->delays());
    }

    /**
     * @return iterable<string, array{string|\Throwable}>
     */
    public static function tokenRejections(): iterable
    {
        yield 'close 3500' => [new ServerClosed(3500, 'invalid token')];
        yield 'disconnect push 3500' => [self::disconnect(3500)];
        yield 'reply error 109' => [self::replyError(109)];
    }

    #[Test]
    #[DataProvider('tokenRejections')]
    public function aRejectedTokenRefreshesOnceAndReconnectsAtOnce(string|\Throwable $rejection): void
    {
        $this->start([self::info(), self::info()], [$rejection, $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame(1, $this->tokens->refreshCalls());
        self::assertSame([], $this->clock->delays());
        self::assertNotSame($this->connection->openAt(0)['token'], $this->connection->openAt(1)['token']);
        self::assertSame($this->tokens->current(), $this->connection->openAt(1)['token']);
    }

    #[Test]
    #[DataProvider('tokenRejections')]
    public function aSecondRejectionInARowIsATerminalAuthenticationException(string|\Throwable $rejection): void
    {
        $this->start([self::info(), self::info()], [$rejection, $rejection]);

        try {
            $this->session->poll(10.0);
            self::fail('expected an AuthenticationException');
        } catch (AuthenticationException) {
            self::assertSame(1, $this->tokens->refreshCalls());
        }
        self::assertNull($this->session->poll(1.0));
        self::assertStringEndsWith(':stop', (string) end($this->log));
    }

    /**
     * @return iterable<string, array{float, bool}>
     */
    public static function rejectionGaps(): iterable
    {
        yield 'second rejection after 59.5 s up is terminal' => [59.5, true];
        yield 'second rejection after 60 s up is allowed' => [60.0, false];
    }

    #[Test]
    #[DataProvider('rejectionGaps')]
    public function theTokenRetryIsAllowedAgainAfterAStableConnection(float $uptime, bool $terminal): void
    {
        $this->start([self::info(), self::info(), self::info()], [new ServerClosed(3500), [$uptime, new ServerClosed(3500)], $this->product()]);

        if ($terminal) {
            $this->expectException(AuthenticationException::class);
        }
        self::assertInstanceOf(ProductAdded::class, $this->session->poll(100.0));
        self::assertSame(2, $this->tokens->refreshCalls());
    }

    #[Test]
    public function aRejectedConnectCommand109RefreshesOnceThenIsTerminal(): void
    {
        $this->start([new ConnectRejected(109), new ConnectRejected(109)]);

        $this->expectException(AuthenticationException::class);
        try {
            $this->session->poll(10.0);
        } finally {
            self::assertSame(1, $this->tokens->refreshCalls());
        }
    }

    #[Test]
    public function aRejectedConnectCommand109ThenAnAcceptedOneConnects(): void
    {
        $this->start([new ConnectRejected(109), self::info()], [$this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));
        self::assertSame(1, $this->tokens->refreshCalls());
    }

    #[Test]
    public function aStreamStatus401RefreshesOnceThenThrowsTheAuthenticationException(): void
    {
        $this->start([HttpException::fromStatus(401, ''), HttpException::fromStatus(401, '')]);

        $this->expectException(AuthenticationException::class);
        try {
            $this->session->poll(10.0);
        } finally {
            self::assertSame(1, $this->tokens->refreshCalls());
        }
    }

    #[Test]
    public function expiredTokenCloseCode3005RefreshesAndReconnectsWithBackoff(): void
    {
        $this->start([self::info(), self::info()], [new ServerClosed(3005), $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame(1, $this->tokens->refreshCalls());
        $this->assertDelays([0.5], $this->clock->delays());
    }

    #[Test]
    public function aProviderAuthenticationFailureIsTerminalAndATransportOneIsRetried(): void
    {
        $this->start([self::info()], [$this->product()]);
        $this->tokens->failNextWith(new TransportException('net'));

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));
        $this->assertDelays([0.5], $this->clock->delays());

        $this->start([self::info()]);
        $this->tokens->failNextWith(HttpException::fromStatus(401, ''));
        $this->expectException(AuthenticationException::class);
        $this->session->poll(10.0);
    }

    // --- close and disconnect codes ------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function codes(): iterable
    {
        yield '2999 reconnects (outside the Centrifugo ranges)' => [2999, true];
        yield '3000 reconnects' => [3000, true];
        yield '3499 reconnects' => [3499, true];
        yield '3501 terminal' => [3501, false];
        yield '3999 terminal' => [3999, false];
        yield '4000 reconnects' => [4000, true];
        yield '4499 reconnects' => [4499, true];
        yield '4500 terminal' => [4500, false];
        yield '4999 terminal' => [4999, false];
        yield '5000 reconnects (outside the Centrifugo ranges)' => [5000, true];
        yield '1000 reconnects (WebSocket normal closure)' => [1000, true];
        yield '1001 reconnects (going away)' => [1001, true];
        yield '1006 reconnects' => [1006, true];
    }

    #[Test]
    #[DataProvider('codes')]
    public function aCloseFrameCodeDecidesBetweenReconnectAndStop(int $code, bool $reconnects): void
    {
        $this->start([self::info(), self::info()], [new ServerClosed($code, "why\x01"), $this->product()]);

        $this->assertCodeOutcome($code, $reconnects);
    }

    #[Test]
    #[DataProvider('codes')]
    public function aDisconnectPushCodeDecidesBetweenReconnectAndStop(int $code, bool $reconnects): void
    {
        $this->start([self::info(), self::info()], [self::disconnect($code, 'why'), $this->product()]);

        $this->assertCodeOutcome($code, $reconnects);
    }

    private function assertCodeOutcome(int $code, bool $reconnects): void
    {
        if ($reconnects) {
            self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));
            self::assertCount(2, $this->connection->opens());
            self::assertContains(\sprintf('disconnected:%d:retry', $code), $this->log);

            return;
        }
        try {
            $this->session->poll(10.0);
            self::fail('expected a LiveConnectionException');
        } catch (LiveConnectionException $e) {
            self::assertSame($code, $e->closeCode);
            self::assertStringStartsWith('why', $e->reason);
        }
        self::assertCount(1, $this->connection->opens());
        self::assertContains(\sprintf('disconnected:%d:stop', $code), $this->log);
        self::assertNull($this->session->poll(1.0));
    }

    // --- closes outside the Centrifugo ranges: bounded reconnect -------------------------------------------------

    /**
     * @return list<ServerClosed>
     */
    private function closes(int $count, int $code): array
    {
        return array_fill(0, $count, new ServerClosed($code, 'bye'));
    }

    #[Test]
    #[DataProvider('otherCodes')]
    public function otherCodesReconnectWithTheNormalBackoff(int $code): void
    {
        $this->start([self::info(), self::info(), self::info()], [new ServerClosed($code), new ServerClosed($code), $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(100.0));

        $this->assertDelays([0.5, 1.0], $this->clock->delays());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function otherCodes(): iterable
    {
        yield '1000' => [1000];
        yield '1001' => [1001];
    }

    #[Test]
    public function fiveConsecutiveOtherCodeClosesReconnectAndTheSixthIsTerminalWithTheCode(): void
    {
        $this->start(
            array_fill(0, 6, self::info()),
            [...$this->closes(5, 1001), new ServerClosed(1001, "gone\x01"), $this->product()],
        );

        try {
            $this->session->poll(1000.0);
            self::fail('expected a LiveConnectionException');
        } catch (LiveConnectionException $e) {
            self::assertSame(1001, $e->closeCode);
            self::assertStringStartsWith('gone', $e->reason);
        }
        self::assertCount(6, $this->connection->opens());
        self::assertContains('disconnected:1001:stop', $this->log);
        self::assertSame(5, substr_count(implode(',', $this->log), 'disconnected:1001:retry'));
    }

    #[Test]
    public function fiveOtherCodeClosesThenAnEventStillDelivers(): void
    {
        $this->start(array_fill(0, 6, self::info()), [...$this->closes(5, 1000), $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));
    }

    #[Test]
    public function aConnectionThatStayedUpSixtySecondsResetsTheOtherCloseCounter(): void
    {
        $this->start(
            array_fill(0, 10, self::info()),
            [...$this->closes(4, 1001), [60.0, new ServerClosed(1001)], ...$this->closes(4, 1001), $this->product()],
        );

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));
    }

    #[Test]
    public function aConnectionThatStayedUp59SecondsDoesNotResetTheOtherCloseCounter(): void
    {
        $this->start(
            array_fill(0, 8, self::info()),
            [...$this->closes(4, 1001), [59.0, new ServerClosed(1001)], new ServerClosed(1001)],
        );

        $this->expectException(LiveConnectionException::class);
        $this->session->poll(1000.0);
    }

    #[Test]
    public function aConnectReplyAloneDoesNotResetTheOtherCloseCounter(): void
    {
        $this->start(array_fill(0, 7, self::info()), $this->closes(6, 1006));

        $this->expectException(LiveConnectionException::class);
        $this->session->poll(1000.0);
    }

    #[Test]
    public function mixedClosesCountOnlyTheOtherCodeOnes(): void
    {
        $this->start(
            array_fill(0, 12, self::info()),
            [
                ...$this->closes(3, 1001), ...$this->closes(3, 3000), ...$this->closes(2, 1001),
                new ServerClosed(4000), $this->product(),
            ],
        );

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));
    }

    #[Test]
    public function mixedClosesStillEndAtTheSixthOtherCodeOne(): void
    {
        $this->start(
            array_fill(0, 12, self::info()),
            [...$this->closes(3, 1001), ...$this->closes(3, 3000), ...$this->closes(3, 1001)],
        );

        try {
            $this->session->poll(1000.0);
            self::fail('expected a LiveConnectionException');
        } catch (LiveConnectionException $e) {
            self::assertSame(1001, $e->closeCode);
        }
        self::assertCount(9, $this->connection->opens());
    }

    #[Test]
    #[DataProvider('terminalCodes')]
    public function terminalRangesStayTerminalAtOnce(int $code): void
    {
        $this->start([self::info(), self::info()], [new ServerClosed($code), $this->product()]);

        try {
            $this->session->poll(10.0);
            self::fail('expected a LiveConnectionException');
        } catch (LiveConnectionException $e) {
            self::assertSame($code, $e->closeCode);
        }
        self::assertCount(1, $this->connection->opens());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function terminalCodes(): iterable
    {
        yield '3501' => [3501];
        yield '3999' => [3999];
        yield '4500' => [4500];
        yield '4999' => [4999];
    }

    #[Test]
    public function theMaxReconnectAttemptsCapStillAppliesToOtherCodes(): void
    {
        $this->start(array_fill(0, 3, self::info()), $this->closes(3, 1001), new LiveOptions(maxReconnectAttempts: 1));

        $this->expectException(LiveConnectionException::class);
        $this->session->poll(1000.0);
    }

    /**
     * @return iterable<string, array{ConnectRejected, bool}>
     */
    public static function rejectedConnects(): iterable
    {
        yield 'permanent error -> terminal' => [new ConnectRejected(101, false), false];
        yield 'temporary error -> retried' => [new ConnectRejected(100, true), true];
    }

    #[Test]
    #[DataProvider('rejectedConnects')]
    public function aRejectedConnectCommandStopsOrRetriesByTheTemporaryFlag(ConnectRejected $rejection, bool $retries): void
    {
        $this->start([$rejection, self::info()], [$this->product()]);

        if (!$retries) {
            $this->expectException(LiveConnectionException::class);
        }
        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));
        self::assertContains('error:' . ConnectRejected::class, $this->log);
    }

    /**
     * @return iterable<string, array{int, ?int, float|null}>
     */
    public static function httpStatuses(): iterable
    {
        yield '429 Retry-After 10 -> wait 10' => [429, 10, 10.0];
        yield '429 Retry-After 30 -> wait 30' => [429, 30, 30.0];
        yield '429 Retry-After 0 -> backoff, never shorter' => [429, 0, 0.5];
        yield '503 Retry-After 0 -> backoff, never shorter' => [503, 0, 0.5];
        yield '503 Retry-After 300 -> wait 300' => [503, 300, 300.0];
        yield '503 Retry-After 301 -> capped at 300' => [503, 301, 300.0];
        yield '503 without Retry-After -> backoff' => [503, null, 0.5];
        yield '500 -> backoff' => [500, null, 0.5];
        yield '502 -> backoff' => [502, null, 0.5];
        yield '400 -> terminal' => [400, null, null];
        yield '403 -> terminal' => [403, null, null];
        yield '404 -> terminal' => [404, null, null];
    }

    #[Test]
    #[DataProvider('httpStatuses')]
    public function aStreamHttpStatusWaitsReconnectsOrStops(int $status, ?int $retryAfter, ?float $wait): void
    {
        $this->start([HttpException::fromStatus($status, '', $retryAfter), self::info()], [$this->product()]);

        if ($wait === null) {
            $this->expectException(HttpException::class);
            $this->session->poll(1000.0);

            return;
        }
        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));
        $this->assertDelays([$wait], $this->clock->delays());
        self::assertSame(0, $this->tokens->refreshCalls());
    }

    #[Test]
    public function retryAfterZeroNeverShortensTheGrowingBackoff(): void
    {
        $this->start([
            HttpException::fromStatus(429, '', 0),
            HttpException::fromStatus(503, '', 0),
            HttpException::fromStatus(429, '', 1),
            self::info(),
        ], [$this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));

        $this->assertDelays([0.5, 1.0, 2.0], $this->clock->delays());
    }

    #[Test]
    public function aRetryAfterLongerThanTheBackoffWinsAndStillCountsTheAttempt(): void
    {
        $this->start([HttpException::fromStatus(429, '', 30), new TransportException('x'), self::info()], [$this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));

        $this->assertDelays([30.0, 1.0], $this->clock->delays());
    }

    private static function unsubscribe(int $code = 2500): string
    {
        return json_encode(['push' => ['channel' => 'extra', 'unsubscribe' => ['code' => $code]]], \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function aRepeatedServerUnsubscribeRightAfterConnectBacksOffWithGrowingDelays(): void
    {
        $this->start(
            [self::info(), self::info(), self::info(), self::info()],
            [self::unsubscribe(), self::unsubscribe(), self::unsubscribe(), $this->product()],
        );
        $this->session->subscribe('extra');
        $this->connection->failSubscribe();

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));

        $this->assertDelays([0.5, 1.0, 2.0], $this->clock->delays());
        self::assertCount(4, $this->connection->opens());
    }

    /**
     * @return iterable<string, array{float, list<float>}>
     */
    public static function unsubscribeUptimes(): iterable
    {
        yield 'up 59.5 s -> backoff' => [59.5, [0.5]];
        yield 'up 60 s -> at once' => [60.0, []];
    }

    /**
     * @param list<float> $expected
     */
    #[Test]
    #[DataProvider('unsubscribeUptimes')]
    public function aServerUnsubscribeReconnectsAtOnceOnlyAfterAStableConnection(float $uptime, array $expected): void
    {
        $this->start([self::info(), self::info()], [[$uptime, self::unsubscribe()], $this->product()]);
        $this->session->subscribe('extra');
        $this->connection->failSubscribe();

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));

        $this->assertDelays($expected, $this->clock->delays());
        self::assertCount(2, $this->connection->opens());
    }

    #[Test]
    public function aUserSubscribeOnSseReconnectsAtOnceEvenOnAFreshConnection(): void
    {
        $this->start([self::info(), self::info()], [$this->product()]);
        $this->connection->failSubscribe();
        $this->session->connect();
        $this->session->subscribe('extra');

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));

        self::assertSame([], $this->clock->delays());
        self::assertSame(['extra'], $this->connection->openAt(1)['channels']);
    }

    #[Test]
    public function aShortTtlRefreshHappensOncePerStreakAndTheConnectionIsKept(): void
    {
        $this->start(
            [self::info(ttl: 600), self::info(ttl: 600), self::info(ttl: 600)],
            [new ServerClosed(3000), $this->product()],
        );

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));

        self::assertSame(1, $this->tokens->refreshCalls());
        self::assertCount(3, $this->connection->opens());
        $this->assertDelays([0.5], $this->clock->delays());
    }

    #[Test]
    public function aShortTtlRefreshIsAllowedAgainAfterAConnectionStayedUpSixtySeconds(): void
    {
        $this->start(
            [self::info(ttl: 600), self::info(ttl: 600), self::info(ttl: 600), self::info(ttl: 600)],
            [[60.0, new ServerClosed(3000)], $this->product()],
        );

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(1000.0));

        self::assertSame(2, $this->tokens->refreshCalls());
        self::assertCount(4, $this->connection->opens());
    }

    #[Test]
    public function aTtlOfSixHundredAndOneSchedulesTheRefreshOneSecondIn(): void
    {
        $this->start([self::info(ping: 1000, ttl: 601), self::info()], [null, $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll());

        self::assertEqualsWithDelta($this->startTimestamp() + 1.0, $this->connection->deadlineAt(0), 1e-3);
        self::assertSame(1, $this->tokens->refreshCalls());
        // up for 1 s only: the refresh reconnect goes through the backoff, not at once
        $this->assertDelays([0.5], $this->clock->delays());
    }

    #[Test]
    public function aRepeatedTtlOfSixHundredAndOneRefreshesOnceInTheFirstSixtySecondsAndKeepsTheConnection(): void
    {
        $this->start(
            [self::info(ping: 1000, ttl: 601), self::info(ping: 1000, ttl: 601), self::info()],
            [null, null, null, null, $this->product()],
        );

        self::assertNull($this->session->poll(30.0));

        // the first connection refreshed at +1 s (backoff 0.5 s); the second is kept although its refresh is due
        self::assertSame(1, $this->tokens->refreshCalls());
        self::assertCount(2, $this->connection->opens());

        self::assertInstanceOf(ProductAdded::class, $this->session->poll());

        self::assertSame(2, $this->tokens->refreshCalls());
        self::assertEqualsWithDelta($this->startTimestamp() + 1.5 + 60.0, $this->connection->deadlineAt(3), 1e-3);
        $this->assertDelays([0.5], $this->clock->delays());
    }

    /**
     * The scenario of a message that arrives `$early` seconds before the connection has been up 60 s, after a ttl-driven
     * refresh already happened in the streak.
     *
     * @return array{int, int, list<float>}
     */
    private function runAroundTheStableBoundary(float $early): array
    {
        $this->start(
            [self::info(), self::info(ttl: 601), self::info(), self::info()],
            [new ServerClosed(3005), null, [33.0, '{}'], [26.0 - $early, '{}'], new ServerClosed(3500), [1.0, new ServerClosed(3500)], new ServerClosed(3500), new ServerClosed(3500)],
        );
        try {
            $this->session->run(200.0);
        } catch (\Throwable) {
            // the script ends with an exhausted queue; only the counts matter
        }

        return [$this->tokens->refreshCalls(), \count($this->connection->opens()), $this->clock->delays()];
    }

    #[Test]
    public function aMessageOneMicrosecondBeforeSixtySecondsUpIsJudgedStableByEveryDecision(): void
    {
        // 1 us is inside the comparison tolerance of the clock, so it must count as 60 s up everywhere: the scheduled
        // refresh, the server-triggered reconnect and the streak reset must agree.
        $exact = $this->runAroundTheStableBoundary(0.0);
        $early = $this->runAroundTheStableBoundary(0.000001);

        self::assertSame($exact[0], $early[0]);
        self::assertSame($exact[1], $early[1]);
        $this->assertDelays($exact[2], $early[2]);
    }

    #[Test]
    public function aStormOfExpiredTokenCloses3005RefreshesOnceAndStillBacksOff(): void
    {
        $this->start(array_fill(0, 12, self::info()), [...array_fill(0, 10, new ServerClosed(3005)), $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(100000.0));

        self::assertSame(1, $this->tokens->refreshCalls());
        $this->assertDelays([0.5, 1.0, 2.0, 4.0, 8.0, 16.0, 20.0, 20.0, 20.0, 20.0], $this->clock->delays());
    }

    #[Test]
    public function aShortTtlRefreshAndAnAuthRejectionAreTwoRefreshesAtMostThenTheNextRejectionIsTerminal(): void
    {
        $this->start(
            [self::info(ttl: 300), self::info(ttl: 300), self::info(ttl: 300)],
            [new ServerClosed(3500), new ServerClosed(3500)],
        );

        try {
            $this->session->poll(100000.0);
            self::fail('expected the second rejection to be terminal');
        } catch (AuthenticationException) {
        }

        self::assertSame(2, $this->tokens->refreshCalls());
        self::assertCount(3, $this->connection->opens());
    }

    /**
     * @return iterable<string, array{\Closure(self): array{list<ConnectInfo|\Throwable>, list<string|\Throwable|array{float, string|\Throwable}|null>}, bool}>
     */
    public static function misbehaviours(): iterable
    {
        $closed = static fn(self $t): array => [array_fill(0, 60, self::info()), [...array_fill(0, 20, new ServerClosed(3000)), $t->product()]];
        yield 'close 3000 after the connect reply' => [$closed, false];
        yield 'EOF after the connect reply' => [static fn(self $t): array => [array_fill(0, 60, self::info()), [...array_fill(0, 20, new TransportException('eof')), $t->product()]], false];
        yield '429 Retry-After 0' => [static fn(self $t): array => [[...array_fill(0, 20, HttpException::fromStatus(429, '', 0)), self::info()], [$t->product()]], false];
        yield '503 Retry-After 0' => [static fn(self $t): array => [[...array_fill(0, 20, HttpException::fromStatus(503, '', 0)), self::info()], [$t->product()]], false];
        yield 'unsubscribe 2500 on SSE' => [static fn(self $t): array => [array_fill(0, 60, self::info()), [...array_fill(0, 20, self::unsubscribe()), $t->product()]], true];
        yield 'ttl 600' => [static fn(self $t): array => [array_fill(0, 60, self::info(ttl: 600)), [...array_fill(0, 20, new ServerClosed(3000)), $t->product()]], false];
        yield 'close 3005 after the connect reply' => [static fn(self $t): array => [array_fill(0, 60, self::info()), [...array_fill(0, 20, new ServerClosed(3005)), $t->product()]], false];
        yield 'ttl 601' => [static fn(self $t): array => [array_fill(0, 60, self::info(ping: 100000, ttl: 601)), [...array_merge(...array_fill(0, 20, [null, new ServerClosed(3000)])), $t->product()]], false];
        yield 'ttl 0' => [static fn(self $t): array => [array_fill(0, 60, self::info(ttl: 0)), [...array_fill(0, 20, new ServerClosed(3000)), $t->product()]], false];
        yield 'ping 0' => [static fn(self $t): array => [array_fill(0, 60, self::info(ping: 0)), [...array_fill(0, 20, null), $t->product()]], false];
    }

    /**
     * @param \Closure(self): array{list<ConnectInfo|\Throwable>, list<string|\Throwable|array{float, string|\Throwable}|null>} $script
     */
    #[Test]
    #[DataProvider('misbehaviours')]
    public function aMisbehavingServerCannotMakeTheSessionReconnectFasterThanItsBackoff(\Closure $script, bool $subscribes): void
    {
        [$opens, $reads] = $script($this);
        $this->start($opens, $reads, fraction: 0.5);
        if ($subscribes) {
            $this->session->subscribe('extra');
            $this->connection->failSubscribe();
        }

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(100000.0));

        $floor = 0.0;
        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            $floor += 0.5 * min(20.0, 0.5 * 2 ** ($attempt - 1));
        }
        self::assertGreaterThanOrEqual($floor - 1e-6, array_sum($this->clock->delays()));
        self::assertLessThanOrEqual(1, $this->tokens->refreshCalls());
    }

    #[Test]
    public function aTerminalHttpStatusIsTheOriginalException(): void
    {
        $this->start([HttpException::fromStatus(404, '')]);

        $this->expectException(GeneralHttpException::class);
        $this->session->poll(1.0);
    }

    // --- subscriptions -------------------------------------------------------------------------------------------

    #[Test]
    public function channelsSubscribedBeforeTheConnectGoIntoTheOpenCall(): void
    {
        $this->start([self::info()], [null]);
        $this->session->subscribe('market_products_updates');
        $this->session->subscribe('market_products_updates');

        $this->session->poll(1.0);

        self::assertSame(['market_products_updates'], $this->connection->openAt(0)['channels']);
    }

    #[Test]
    public function aChannelAddedAfterTheConnectIsSubscribedAtOnceOnAWebSocket(): void
    {
        $this->start([self::info()]);
        $this->session->connect();

        $this->session->subscribe('extra');

        self::assertSame(['extra'], $this->connection->subscribed());
        self::assertCount(1, $this->connection->opens());
    }

    #[Test]
    public function aChannelAddedAfterTheConnectReconnectsWithTheNewListWhenTheTransportCannotSubscribe(): void
    {
        $this->start([self::info(), self::info()], [$this->product()]);
        $this->connection->failSubscribe();
        $this->session->connect();

        $this->session->subscribe('extra');
        $this->session->poll(10.0);

        self::assertSame(['extra'], $this->connection->openAt(1)['channels']);
        self::assertSame([], $this->clock->delays());
        self::assertSame(['connecting', 'connected:0', 'disconnected:null:retry', 'connecting', 'connected:1'], $this->log);
    }

    #[Test]
    public function aSubscribeFailureThatIsNotATransportErrorIsNotTurnedIntoAReconnect(): void
    {
        $this->start([self::info(), self::info()], [$this->product()]);
        $this->connection->throwOnSubscribe(new \InvalidArgumentException('bad channel'));
        $this->session->connect();

        $this->expectException(\InvalidArgumentException::class);

        $this->session->subscribe('extra');
    }

    #[Test]
    public function aTransportErrorOnSubscribeReconnectsWithBackoff(): void
    {
        $this->start([self::info(), self::info()], [$this->product()]);
        $this->connection->throwOnSubscribe(new TransportException('send failed'));
        $this->session->connect();

        $this->session->subscribe('extra');
        $this->session->poll(10.0);

        self::assertCount(2, $this->connection->opens());
        self::assertSame(['extra'], $this->connection->openAt(1)['channels']);
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function unsubscribeCodes(): iterable
    {
        yield 'code 2499 drops the channel' => [2499, false];
        yield 'code 2500 resubscribes' => [2500, true];
        yield 'code 3000 resubscribes' => [3000, true];
    }

    #[Test]
    #[DataProvider('unsubscribeCodes')]
    public function aServerUnsubscribeResubscribesFromCode2500AndDropsTheChannelBelow(int $code, bool $resubscribes): void
    {
        $unsubscribe = json_encode(['push' => ['channel' => 'extra', 'unsubscribe' => ['code' => $code]]], \JSON_THROW_ON_ERROR);
        $this->start([self::info(), self::info()], [$unsubscribe, new ServerClosed(3000), $this->product()]);
        $this->session->subscribe('extra');

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame($resubscribes ? ['extra'] : [], $this->connection->subscribed());
        self::assertSame($resubscribes ? ['extra'] : [], $this->connection->openAt(1)['channels']);
        self::assertSame(!$resubscribes, \in_array('error:' . LiveConnectionException::class, $this->log, true));
    }

    #[Test]
    public function aServerUnsubscribeReconnectsWhenTheTransportCannotSubscribe(): void
    {
        $unsubscribe = json_encode(['push' => ['channel' => 'extra', 'unsubscribe' => ['code' => 2500]]], \JSON_THROW_ON_ERROR);
        $this->start([self::info(), self::info()], [$unsubscribe, $this->product()]);
        $this->session->subscribe('extra');
        $this->connection->failSubscribe();

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertCount(2, $this->connection->opens());
        self::assertSame(['extra'], $this->connection->openAt(1)['channels']);
    }

    #[Test]
    public function aReplyAlreadySubscribedIsASuccessAndOtherReplyErrorsGoToOnError(): void
    {
        $this->start([self::info()], [self::replyError(105), self::replyError(103), $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame(['connecting', 'connected:0', 'error:' . LiveConnectionException::class], $this->log);
    }

    // --- messages and handlers -----------------------------------------------------------------------------------

    #[Test]
    #[DataProvider('pongFlags')]
    public function answersTheServerPingWithAPongOnlyWhenAsked(bool $pong, int $expected): void
    {
        $this->start([self::info(pong: $pong)], ['{}', $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame($expected, $this->connection->pongs());
    }

    /**
     * @return iterable<string, array{bool, int}>
     */
    public static function pongFlags(): iterable
    {
        yield 'pong true' => [true, 1];
        yield 'pong false' => [false, 0];
    }

    #[Test]
    public function aMalformedMessageGoesToOnErrorAndTheStreamContinues(): void
    {
        $this->start([self::info()], ['not json', '[]', $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame(['connecting', 'connected:0', 'error:' . DecodeException::class, 'error:' . DecodeException::class], $this->log);
    }

    #[Test]
    public function aKnownTypeWithMalformedContentIsAnUnknownEventPlusOnError(): void
    {
        $message = json_encode(['type' => 'market_product_added', 'content' => ['price' => '1']], \JSON_THROW_ON_ERROR);
        $raw = json_encode(['push' => ['channel' => 'market_products_updates', 'pub' => ['data' => ['message' => $message]]]], \JSON_THROW_ON_ERROR);
        $this->start([self::info()], [$raw]);

        self::assertInstanceOf(UnknownEvent::class, $this->session->poll(10.0));

        self::assertContains('error:' . DecodeException::class, $this->log);
    }

    #[Test]
    public function aUnrecognisedMessageKindIsIgnored(): void
    {
        $this->start([self::info()], ['{"join":{"info":{}},"channel":"x"}', $this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));

        self::assertSame(['connecting', 'connected:0'], $this->log);
    }

    #[Test]
    public function aHandlerExceptionPropagatesAndTheConnectionStaysUsable(): void
    {
        $this->start([self::info()], [$this->product(), $this->deal()]);
        $this->handlers->onEvent(static function (LiveEvent $event): void {
            if ($event instanceof ProductAdded) {
                throw new \Error('boom');
            }
        });

        try {
            $this->session->poll(10.0);
            self::fail('expected the handler exception');
        } catch (\Error $e) {
            self::assertSame('boom', $e->getMessage());
        }
        $next = $this->session->poll(10.0);

        self::assertInstanceOf(DealCompleted::class, $next);
        self::assertCount(1, $this->connection->opens());
        self::assertSame(0, $this->connection->closes());
    }

    #[Test]
    public function closeFromAHandlerStopsTheSessionAfterTheEvent(): void
    {
        $this->start([self::info()], [$this->product(), $this->product()]);
        $this->handlers->onEvent(function (): void {
            $this->session->close();
        });

        self::assertInstanceOf(ProductAdded::class, $this->session->poll(10.0));
        self::assertNull($this->session->poll(10.0));

        self::assertSame(1, $this->connection->closes());
        self::assertSame('disconnected:null:stop', end($this->log));
        $this->session->close();
        self::assertSame(1, $this->connection->closes());
        self::assertCount(1, $this->connection->opens());
    }

    #[Test]
    public function eventsYieldsUntilClose(): void
    {
        $this->start([self::info()], [$this->product(), $this->deal(), $this->product()]);
        $seen = 0;
        $this->handlers->onEvent(function () use (&$seen): void {
            if (++$seen === 2) {
                $this->session->close();
            }
        });

        $classes = [];
        foreach ($this->session->events() as $event) {
            $classes[] = $event::class;
        }

        self::assertSame([ProductAdded::class, DealCompleted::class], $classes);
    }

    #[Test]
    public function runDispatchesToHandlersUntilTheDuration(): void
    {
        $this->start([self::info()], [$this->product(), $this->product(), null]);
        $seen = 0;
        $this->handlers->onEvent(static function () use (&$seen): void {
            ++$seen;
        });

        $this->session->run(10.0);

        self::assertSame(2, $seen);
        self::assertEqualsWithDelta($this->startTimestamp() + 10.0, $this->connection->deadlineAt(2), 1e-3);
    }

    #[Test]
    public function reconnectedIsFalseOnlyForTheFirstConnection(): void
    {
        $this->start([self::info(), self::info(), self::info()], [new ServerClosed(3000), new ServerClosed(3001), $this->product()]);

        $this->session->poll(100.0);

        self::assertSame(
            ['connecting', 'connected:0', 'disconnected:3000:retry', 'connecting', 'connected:1', 'disconnected:3001:retry', 'connecting', 'connected:1'],
            $this->log,
        );
    }

    #[Test]
    public function connectConnectsEagerlyAndPollDoesNotReconnect(): void
    {
        $this->start([new TransportException('x'), self::info()], [null]);

        $this->session->connect();
        $this->session->poll(1.0);

        self::assertCount(2, $this->connection->opens());
        $this->assertDelays([0.5], $this->clock->delays());
    }

    #[Test]
    public function closeBeforeConnectingDoesNothing(): void
    {
        $this->start([]);

        $this->session->close();

        self::assertNull($this->session->poll(1.0));
        self::assertSame([], $this->connection->opens());
        self::assertSame([], $this->log);
    }

    // --- secrets -------------------------------------------------------------------------------------------------

    #[Test]
    public function logsHoldTheCodeAndTheAttemptOnly(): void
    {
        $this->start([self::info(), self::info()], [new ServerClosed(3000, 'secret-reason-text'), $this->product()]);

        $this->session->poll(10.0);

        foreach ($this->logger->records() as $record) {
            self::assertSame('info', $record['level']);
            self::assertSame([], array_diff(array_keys($record['context']), ['code', 'attempt']));
        }
        self::assertNotSame([], $this->logger->records());
        self::assertStringNotContainsString('secret-reason-text', $this->logger->dump());
        self::assertStringNotContainsString($this->tokens->current(), $this->logger->dump());
        self::assertStringNotContainsString($this->connection->openAt(0)['token'], $this->logger->dump());
    }

    #[Test]
    public function noTokenReachesATraceOfAnExceptionTheSessionThrows(): void
    {
        $tokens = new ScriptedTokens();
        $clock = new ManualClock(self::START);
        $connection = new TokenHoldingConnection();
        $session = new LiveSession(
            $tokens,
            static fn(): TokenHoldingConnection => $connection,
            new LiveOptions(),
            $logger = new MemoryLogger(),
            $clock,
            $clock,
            new LiveHandlers(),
            new FixedRandomSource(1.0),
        );

        $error = TraceArguments::caught(static fn(): ?LiveEvent => $session->poll(10.0));

        self::assertInstanceOf(LiveConnectionException::class, $error);
        self::assertNotSame([], $connection->tokens());
        foreach ($connection->tokens() as $token) {
            self::assertStringNotContainsString($token, $error->getMessage());
            self::assertStringNotContainsString($token, TraceArguments::exportedSdkFrames($error));
            self::assertStringNotContainsString($token, TraceArguments::printedSdkFrames($error));
            self::assertStringNotContainsString($token, $logger->dump());
        }
        self::assertNull($error->getPrevious());
    }

    /**
     * @return iterable<string, array{\Closure(self): \Throwable}>
     */
    public static function terminalFailures(): iterable
    {
        yield 'second token rejection' => [static function (self $t): \Throwable {
            $t->start([self::info(), self::info()], [new ServerClosed(3500), new ServerClosed(3500)]);

            return TraceArguments::caught(fn(): ?LiveEvent => $t->session->poll(10.0));
        }];
        yield 'terminal close code' => [static function (self $t): \Throwable {
            $t->start([self::info()], [new ServerClosed(3600, 'bye')]);

            return TraceArguments::caught(fn(): ?LiveEvent => $t->session->poll(10.0));
        }];
        yield 'attempts exhausted' => [static function (self $t): \Throwable {
            $t->start([new TransportException('x')], options: new LiveOptions(maxReconnectAttempts: 1));
            $t->handlers->onError(static function (): void {});

            return TraceArguments::caught(fn(): ?LiveEvent => $t->session->poll(10.0));
        }];
    }

    /**
     * @param \Closure(self): \Throwable $failure
     */
    #[Test]
    #[DataProvider('terminalFailures')]
    public function noTokenReachesTheTraceOfATerminalFailure(\Closure $failure): void
    {
        $error = $failure($this);

        $tokens = array_column($this->connection->opens(), 'token');
        self::assertNotSame([], $tokens);
        foreach ($tokens as $token) {
            self::assertStringNotContainsString($token, $error->getMessage());
            self::assertStringNotContainsString($token, TraceArguments::exportedSdkFrames($error));
            self::assertStringNotContainsString($token, TraceArguments::printedSdkFrames($error));
            self::assertStringNotContainsString($token, $this->logger->dump());
        }
    }
}
