<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\LiveConnectionException;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\Event\DealCompleted;
use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\LiveDisconnected;
use WhiteMarket\Sdk\Live\Event\LiveEvent;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\Event\UnknownEvent;
use WhiteMarket\Sdk\Live\Live;
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

#[CoversClass(Live::class)]
final class LiveTest extends TestCase
{
    private ScriptedConnection $connection;

    private Live $live;

    /**
     */
    protected function setUp(): void
    {
        $this->live([]);
    }

    /**
     * @param list<string|\Throwable|array{float, string|\Throwable}|null> $reads
     */
    private function live(array $reads, int $opens = 1): void
    {
        $clock = new ManualClock();
        $this->connection = new ScriptedConnection($clock, array_fill(0, $opens, new ConnectInfo(25, true, 86400, [])), $reads);
        $connection = $this->connection;
        $this->live = new Live(new LiveSession(
            new ScriptedTokens(),
            static fn(): ScriptedConnection => $connection,
            new LiveOptions(),
            new MemoryLogger(),
            $clock,
            $clock,
            $handlers = new LiveHandlers(),
            new FixedRandomSource(1.0),
        ), $handlers);
    }

    private function product(): string
    {
        return LiveFixture::raw('ws_product_added.json');
    }

    private function personal(string $channel = '#42'): string
    {
        $message = json_encode(LiveFixture::message('deal_completed.json'), \JSON_THROW_ON_ERROR);

        return json_encode(['push' => ['channel' => $channel, 'pub' => ['data' => ['message' => $message]]]], \JSON_THROW_ON_ERROR);
    }

    private function broadcast(): string
    {
        return json_encode(['push' => ['channel' => 'broadcast', 'pub' => ['data' => ['message' => '{"type":"x","content":{}}']]]], \JSON_THROW_ON_ERROR);
    }

    #[Test]
    public function subscribeProductsSendsTheChannelAndItsHandlerSeesThatChannelOnly(): void
    {
        $this->live([$this->personal(), $this->product()]);
        $seen = [];
        $this->live->subscribeProducts(static function (LiveEvent $event) use (&$seen): void {
            $seen[] = $event::class;
        });

        $this->live->poll(5.0);
        $this->live->poll(5.0);

        self::assertSame([ProductAdded::class], $seen);
        self::assertSame([['market_products_updates']], array_column($this->connection->opens(), 'channels'));
    }

    #[Test]
    public function subscribePersonalSendsNoChannelAndItsHandlerSeesPersonalChannelsOnly(): void
    {
        $this->live([$this->product(), $this->personal(), $this->personal('#7')]);
        $seen = [];
        $this->live->subscribePersonal(static function (LiveEvent $event) use (&$seen): void {
            $seen[] = $event::class;
        });

        $this->live->poll(5.0);
        $this->live->poll(5.0);
        $this->live->poll(5.0);

        self::assertSame([DealCompleted::class, DealCompleted::class], $seen);
        self::assertSame([[]], array_column($this->connection->opens(), 'channels'));
    }

    #[Test]
    public function subscribeWithoutAHandlerStillSubscribes(): void
    {
        $this->live([null]);
        $this->live->subscribeProducts();
        $this->live->subscribePersonal();

        $this->live->poll(1.0);

        self::assertSame([['market_products_updates']], array_column($this->connection->opens(), 'channels'));
    }

    /**
     * @return iterable<string, array{class-string<LiveEvent>, int}>
     */
    public static function typeFilters(): iterable
    {
        yield 'LiveEvent matches all four' => [LiveEvent::class, 4];
        yield 'ProductAdded matches one' => [ProductAdded::class, 1];
        yield 'DealCompleted matches two' => [DealCompleted::class, 2];
        yield 'UnknownEvent matches the broadcast one' => [UnknownEvent::class, 1];
    }

    /**
     * @param class-string<LiveEvent> $type
     */
    #[Test]
    #[DataProvider('typeFilters')]
    public function onMatchesTheEventClass(string $type, int $expected): void
    {
        $this->live([$this->product(), $this->personal(), $this->broadcast(), $this->personal('#9')]);
        $count = 0;
        $this->live->on($type, static function () use (&$count): void {
            ++$count;
        });

        for ($i = 0; $i < 4; ++$i) {
            $this->live->poll(5.0);
        }

        self::assertSame($expected, $count);
    }

    #[Test]
    public function lifecycleCallbacksAreCalledInOrder(): void
    {
        $this->live([new ServerClosed(3000, 'bye'), $this->product()], 2);
        $log = [];
        $this->live->onConnecting(static function () use (&$log): void {
            $log[] = 'connecting';
        });
        $this->live->onConnected(static function (LiveConnected $e) use (&$log): void {
            $log[] = $e->reconnected ? 'reconnected' : 'connected';
        });
        $this->live->onDisconnected(static function (LiveDisconnected $e) use (&$log): void {
            $log[] = 'disconnected ' . ($e->code ?? 'null') . ' ' . $e->reason;
        });

        $this->live->poll(10.0);
        $this->live->close();

        self::assertSame(['connecting', 'connected', 'disconnected 3000 bye', 'connecting', 'reconnected', 'disconnected null '], $log);
    }

    #[Test]
    public function onErrorGetsAMalformedMessage(): void
    {
        $this->live(['nope', $this->product()]);
        $errors = [];
        $this->live->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e::class;
        });

        self::assertInstanceOf(ProductAdded::class, $this->live->poll(5.0));
        self::assertCount(1, $errors);
    }

    #[Test]
    public function eventsConnectRunAndCloseWork(): void
    {
        $this->live([$this->product(), $this->product(), null]);
        $this->live->connect();
        $count = 0;
        $this->live->on(ProductAdded::class, function () use (&$count): void {
            if (++$count === 2) {
                $this->live->close();
            }
        });

        $this->live->run(30.0);

        self::assertSame(2, $count);
        self::assertNull($this->live->poll(1.0));
        $this->live->close();
        self::assertSame(1, $this->connection->closes());
    }

    #[Test]
    public function aTerminalFailureIsThrownFromThePull(): void
    {
        $this->live([new ServerClosed(3600)]);

        $this->expectException(LiveConnectionException::class);
        $this->live->poll(5.0);
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function badSeconds(): iterable
    {
        yield 'negative' => [-0.1];
        yield 'NaN' => [\NAN];
    }

    #[Test]
    #[DataProvider('badSeconds')]
    public function pollRejectsABadTimeout(float $seconds): void
    {
        $this->live([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->live->poll($seconds);
    }

    #[Test]
    #[DataProvider('badSeconds')]
    public function runRejectsABadDuration(float $seconds): void
    {
        $this->live([]);

        $this->expectException(\InvalidArgumentException::class);
        $this->live->run($seconds);
    }

    #[Test]
    public function aZeroTimeoutIsAllowedAndReturnsWhatIsReady(): void
    {
        $this->live([$this->product()]);

        self::assertInstanceOf(ProductAdded::class, $this->live->poll(0.0));
    }
}
