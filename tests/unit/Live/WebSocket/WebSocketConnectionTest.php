<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\ConnectRejected;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\ServerClosed;
use WhiteMarket\Sdk\Live\WebSocket\CapturingStreamFactory;
use WhiteMarket\Sdk\Live\WebSocket\WebSocketConnection;
use WhiteMarket\Sdk\Tests\Support\FixtureServer;
use WhiteMarket\Sdk\Tests\Support\SyntheticJwt;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

/**
 * The WebSocket connection against the synthetic server of `tests/fixtures/servers/websocket.php` over a real socket
 * (plan assumptions A5 and A7: a frame sent while the client waits is seen, a burst in one TCP write is fully delivered).
 */
#[CoversClass(WebSocketConnection::class)]
#[CoversClass(CapturingStreamFactory::class)]
final class WebSocketConnectionTest extends TestCase
{
    private ?FixtureServer $server = null;

    private ?WebSocketConnection $connection = null;

    protected function tearDown(): void
    {
        $this->connection?->close();
        $this->server?->stop();
        $this->server = null;
        $this->connection = null;
    }

    private function connection(string $scenario, ?LiveOptions $options = null, ?int $size = null, ?float $idleSeconds = null): WebSocketConnection
    {
        $this->server = FixtureServer::start('websocket', $size === null ? [$scenario] : [$scenario, (string) $size]);

        return $this->connection = new WebSocketConnection($this->server->baseUrl(), $options ?? new LiveOptions(), $idleSeconds);
    }

    /**
     * @param list<string> $channels
     */
    private function open(WebSocketConnection $connection, string $jwt = '', array $channels = ['#42']): ConnectInfo
    {
        return $connection->open(new Secret($jwt !== '' ? $jwt : SyntheticJwt::withClaims(['sub' => '42'])), $channels, 10);
    }

    #[Test]
    public function opensWithAConnectCommandTheServerAcceptsAndReturnsTheConnectInfo(): void
    {
        $connection = $this->connection('hello');

        $info = $this->open($connection);

        self::assertSame(25, $info->ping);
        self::assertTrue($info->pong);
        self::assertSame(86400, $info->ttl);
        self::assertSame(86400, $info->ttl);
        self::assertSame(['#42'], $info->subs);
        // The server closes with 4999 when the command shape or the User-Agent is wrong; it did not.
        self::assertNull($connection->read(0.5));
    }

    #[Test]
    public function aReadWithoutDataReturnsNullAtItsDeadlineAndTheConnectionStaysUsable(): void
    {
        $connection = $this->connection('delayed');
        $this->open($connection);

        $start = microtime(true);
        $idle = $connection->read(1);
        $elapsed = microtime(true) - $start;

        self::assertNull($idle);
        self::assertGreaterThanOrEqual(0.95, $elapsed);
        self::assertLessThan(1.4, $elapsed);
        // The frame sent 2.5 s after the connect reply arrives intact while the client waits (A7 control).
        self::assertSame('late', $connection->read(5));
    }

    #[Test]
    public function aFrameWithSeveralMessagesIsSplitOnNewlinesAndDeliveredOnePerRead(): void
    {
        $connection = $this->connection('multi');
        $this->open($connection);

        self::assertSame('{"n":1}', $connection->read(5));
        self::assertSame('{"n":2}', $connection->read(5));
        self::assertSame('{"n":3}', $connection->read(5));
        // The trailing newline made no empty message.
        self::assertNull($connection->read(0.3));
    }

    #[Test]
    public function aBurstOfFramesInOneWriteIsDeliveredWithoutWaitingForMoreData(): void
    {
        $connection = $this->connection('burst');
        $this->open($connection);

        $start = microtime(true);
        for ($i = 0; $i < 1000; ++$i) {
            self::assertSame('{"n":' . $i . '}', $connection->read(5));
        }

        self::assertLessThan(2.0, microtime(true) - $start);
    }

    #[Test]
    public function aPingFrameIsAnsweredWithAPongFrameAndNotDelivered(): void
    {
        $connection = $this->connection('ping-frame');
        $this->open($connection);

        // `ok` follows only when the server got the pong frame back; otherwise it closes with 4998.
        self::assertSame('ok', $connection->read(5));
    }

    #[Test]
    public function theJsonPingIsDeliveredAndPongAnswersItWithAnEmptyObject(): void
    {
        $connection = $this->connection('json-ping');
        $this->open($connection);

        self::assertSame('{}', $connection->read(5));
        $connection->pong();
        self::assertSame('ok', $connection->read(5));
    }

    /**
     * @return iterable<string, array{string, int, string}>
     */
    public static function closeFrames(): iterable
    {
        yield 'terminal 3501' => ['close3501', 3501, 'bye'];
        yield 'terminal 4501' => ['close4501', 4501, 'bye'];
        yield 'control bytes and a long reason' => ['close-ctrl', 3000, 'a?b?[31m?' . str_repeat('z', 128 - 9)];
    }

    #[Test]
    #[DataProvider('closeFrames')]
    public function aCloseFrameIsAServerClosedWithItsCodeAndASanitisedReason(string $scenario, int $code, string $reason): void
    {
        $connection = $this->connection($scenario);
        $this->open($connection);

        $closed = TraceArguments::caught(static fn(): ?string => $connection->read(5));

        self::assertInstanceOf(ServerClosed::class, $closed);
        self::assertSame($code, $closed->closeCode);
        self::assertSame($reason, $closed->reason);
        self::assertSame(\sprintf('The server closed the live connection with code %d.', $code), $closed->getMessage());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function messageSizes(): iterable
    {
        yield 'exactly the cap' => [1024, true];
        yield 'one byte over the cap' => [1025, false];
    }

    #[Test]
    #[DataProvider('messageSizes')]
    public function aMessageOverTheCapIsATransportError(int $size, bool $delivered): void
    {
        $connection = $this->connection('oversize', new LiveOptions(maxMessageBytes: 1024), $size);
        $this->open($connection);

        if ($delivered) {
            self::assertSame(str_repeat('x', $size), $connection->read(5));

            return;
        }
        $this->expectException(TransportException::class);
        $connection->read(5);
    }

    #[Test]
    public function aConnectionClosedWithoutACloseFrameIsATransportErrorAfterTheQueuedMessage(): void
    {
        $connection = $this->connection('eof');
        $this->open($connection);

        self::assertSame('one', $connection->read(5));
        $this->expectException(TransportException::class);
        $connection->read(5);
    }

    #[Test]
    public function aBinaryFrameIsATransportError(): void
    {
        $connection = $this->connection('binary');
        $this->open($connection);

        $this->expectException(TransportException::class);
        $connection->read(5);
    }

    #[Test]
    public function subscribesToTheMissingChannelsAtOpenAndPassesTheRepliesThroughRead(): void
    {
        $connection = $this->connection('subscribe');
        // `#42` is in the connect reply `subs`; only the products channel is subscribed (id 2).
        $this->open($connection, channels: ['#42', 'market_products_updates']);

        self::assertSame('{"id":2,"error":{"code":105,"message":"already subscribed","temporary":false}}', $connection->read(5));

        $connection->subscribe('late-channel');
        self::assertSame('{"id":3,"subscribe":{}}', $connection->read(5));
    }

    #[Test]
    public function anErrorReplyToTheConnectCommandIsAConnectRejectedWithTheCode(): void
    {
        $connection = $this->connection('connect-error');

        $rejected = TraceArguments::caught(fn(): ConnectInfo => $this->open($connection));

        self::assertInstanceOf(ConnectRejected::class, $rejected);
        self::assertSame(109, $rejected->errorCode);
        self::assertFalse($rejected->temporary);
        self::assertSame('The server rejected the live connect command with error 109.', $rejected->getMessage());
    }

    #[Test]
    public function aFirstMessageThatIsNotAConnectReplyIsATransportError(): void
    {
        $connection = $this->connection('not-connect');

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The first live message is not a connect reply.');
        $this->open($connection);
    }

    #[Test]
    public function noConnectReplyBeforeTheDeadlineIsATransportError(): void
    {
        $connection = $this->connection('silent');

        $start = microtime(true);
        try {
            $connection->open(new Secret(SyntheticJwt::withClaims(['sub' => '42'])), [], 1);
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertSame('The live connection sent no connect reply in time.', $e->getMessage());
        }
        self::assertLessThan(1.6, microtime(true) - $start);
    }

    #[Test]
    public function readingAConnectionThatIsNotOpenIsATransportError(): void
    {
        $connection = new WebSocketConnection('http://localhost:1', new LiveOptions());

        $this->expectException(TransportException::class);
        $connection->read(1);
    }

    #[Test]
    public function closeIsSafeOnAnUnopenedAndOnAClosedConnection(): void
    {
        $connection = $this->connection('hello');
        $connection->close();
        $this->open($connection);

        $connection->close();
        $connection->close();

        $this->expectException(TransportException::class);
        $connection->read(1);
    }

    #[Test]
    public function aHandshakeTheServerRefusesFailsWithoutTheTokenAnywhere(): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $connection = $this->connection('reject');

        $e = TraceArguments::caught(fn(): ConnectInfo => $this->open($connection, $jwt));

        self::assertInstanceOf(TransportException::class, $e);
        $this->assertNoLeak($e, $jwt);
    }

    #[Test]
    public function aRefusedConnectionFailsWithoutTheTokenOrTheUrl(): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $probe = stream_socket_server('tcp://localhost:0');
        self::assertNotFalse($probe);
        $name = (string) stream_socket_get_name($probe, false);
        $port = substr($name, (int) strrpos($name, ':') + 1);
        fclose($probe);
        $connection = new WebSocketConnection('http://localhost:' . $port, new LiveOptions());

        $e = TraceArguments::caught(fn(): ConnectInfo => $this->open($connection, $jwt));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('The live WebSocket could not connect (WebSocket\Exception\ClientException).', $e->getMessage());
        $this->assertNoLeak($e, $jwt, $port);
    }

    #[Test]
    public function aConnectionBrokenAfterOpenLeaksNoTokenEither(): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $connection = $this->connection('eof');
        $this->open($connection, $jwt);
        $connection->read(5);

        $e = TraceArguments::caught(fn(): ?string => $connection->read(5));

        $this->assertNoLeak($e, $jwt);
    }

    #[Test]
    public function aConnectCommandThatCannotBeEncodedFailsWithoutTheToken(): void
    {
        $connection = $this->connection('hello');
        $jwt = "synthetic-jwt-marker-\xff";

        $e = TraceArguments::caught(fn(): ConnectInfo => $this->open($connection, $jwt));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('Sending on the live WebSocket failed (JsonException).', $e->getMessage());
        $this->assertNoLeak($e, 'synthetic-jwt-marker');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function whereTheBadChannelGoes(): iterable
    {
        yield 'open() subscribes it' => [true];
        yield 'subscribe() on an open connection' => [false];
    }

    #[Test]
    #[DataProvider('whereTheBadChannelGoes')]
    public function aChannelThatCannotBeEncodedFailsWithoutTheToken(bool $atOpen): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $connection = $this->connection('hello');
        $channels = $atOpen ? ['#42', "bad-\xff-channel"] : ['#42'];

        $e = TraceArguments::caught(function () use ($connection, $jwt, $channels, $atOpen): void {
            $this->open($connection, $jwt, $channels);
            if (!$atOpen) {
                $connection->subscribe("bad-\xff-channel");
            }
        });

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('Sending on the live WebSocket failed (JsonException).', $e->getMessage());
        $this->assertNoLeak($e, $jwt);
    }

    #[Test]
    public function aBaseUrlThePhrityClientRejectsFailsWithoutTheTokenOrTheUrl(): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $connection = new WebSocketConnection('http://localhost:abc', new LiveOptions());

        $e = TraceArguments::caught(fn(): ConnectInfo => $this->open($connection, $jwt));

        self::assertInstanceOf(TransportException::class, $e);
        $this->assertNoLeak($e, $jwt, 'abc');
    }

    private function assertNoLeak(\Throwable $e, string $jwt, string $port = ''): void
    {
        $needles = [$jwt, 'synthetic-secret-subject', 'localhost'];
        if ($port !== '') {
            $needles[] = ':' . $port;
        }
        $texts = [TraceArguments::exportedSdkFrames($e), TraceArguments::printedSdkFrames($e)];
        for ($c = $e; $c instanceof \Throwable; $c = $c->getPrevious()) {
            $texts[] = $c->getMessage() . $c->getTraceAsString();
        }
        self::assertNull($e->getPrevious(), 'the exception is never chained');
        foreach ($texts as $text) {
            foreach ($needles as $needle) {
                self::assertStringNotContainsString($needle, $text);
            }
        }
    }

    #[Test]
    public function aFrameTrickledOneByteAtATimeEndsAtTheReadDeadlinePlusIdleNotAtThePayloadTime(): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $connection = $this->connection('trickle', idleSeconds: 1.0);
        $this->open($connection, $jwt);

        $start = microtime(true);
        $e = TraceArguments::caught(static fn(): ?string => $connection->read(1.0));
        $elapsed = microtime(true) - $start;

        // 64 bytes at 0.5 s take 32 s; the bound is the read deadline (1 s) + idle (1 s) + one 0.5 s gap.
        self::assertInstanceOf(TransportException::class, $e);
        self::assertLessThan(4.0, $elapsed);
        self::assertStringNotContainsString($jwt, (string) $e);
    }

    #[Test]
    public function anUpgradeResponseTrickledOneByteAtATimeEndsAtTheOpenDeadlinePlusIdle(): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $this->server = FixtureServer::start('websocket', ['trickle-handshake']);
        $connection = $this->connection = new WebSocketConnection($this->server->baseUrl(), new LiveOptions(), 1.0);

        $start = microtime(true);
        $e = TraceArguments::caught(fn(): ConnectInfo => $connection->open(new Secret($jwt), ['#42'], 1));
        $elapsed = microtime(true) - $start;

        self::assertInstanceOf(TransportException::class, $e);
        self::assertLessThan(4.0, $elapsed);
        self::assertStringNotContainsString($jwt, (string) $e);
    }

    /** @return iterable<string, array{string}> */
    public static function floods(): iterable
    {
        yield 'pong frames' => ['flood-pong'];
        yield 'empty text frames' => ['flood-empty-text'];
        yield 'ping frames' => ['flood-ping'];
    }

    #[Test]
    #[DataProvider('floods')]
    public function aFloodOfCompleteFramesThatCarryNoMessageDoesNotHoldReadPastItsDeadline(string $scenario): void
    {
        $connection = $this->connection($scenario, idleSeconds: 1.0);
        $this->open($connection);

        $start = microtime(true);
        $result = $connection->read(0.5);
        $elapsed = microtime(true) - $start;

        self::assertNull($result);
        self::assertLessThan(1.2, $elapsed, 'read(0.5) returned only after ' . round($elapsed, 1) . ' s');
        // The connection is still usable: the next read returns at its own deadline too.
        self::assertNull($connection->read(0.2));
    }

    #[Test]
    public function aReadOfZeroSecondsStillTakesOneFrameOfWaitingData(): void
    {
        $connection = $this->connection('multi');
        $this->open($connection);
        usleep(300_000);

        self::assertSame('{"n":1}', $connection->read(0));
    }

    #[Test]
    public function aServerThatNeverReadsCannotHoldAWriteMoreThanTheIdleBound(): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $connection = $this->connection('no-read', idleSeconds: 1.0);
        $this->open($connection, $jwt);

        $start = microtime(true);
        // One 8 MB subscribe command cannot fit the socket buffers of a peer that does not read.
        $e = TraceArguments::caught(static fn() => $connection->subscribe(str_repeat('a', 8_000_000)));
        $elapsed = microtime(true) - $start;

        self::assertInstanceOf(TransportException::class, $e);
        self::assertLessThan(3.0, $elapsed);
        $this->assertNoLeak($e, $jwt);
    }

    #[Test]
    public function pongsAnsweringAPingFloodToAServerThatNeverReadsEndInATransportErrorOrANullReadWithinTheBound(): void
    {
        $jwt = SyntheticJwt::withClaims(['sub' => 'synthetic-secret-subject']);
        $connection = $this->connection('ping-flood-no-read', idleSeconds: 1.0);
        $this->open($connection, $jwt);

        $start = microtime(true);
        $ini = ini_set('zend.exception_ignore_args', '0');
        $e = null;
        try {
            $connection->read(0.5);
        } catch (\Throwable $e) {
        } finally {
            ini_set('zend.exception_ignore_args', (string) $ini);
        }
        $elapsed = microtime(true) - $start;

        self::assertLessThan(3.0, $elapsed);
        if ($e instanceof \Throwable) {
            self::assertInstanceOf(TransportException::class, $e);
            $this->assertNoLeak($e, $jwt);
        }
    }

    #[Test]
    public function aPayloadWhoseByteArrivesAloneAsZeroIsDeliveredIntact(): void
    {
        $connection = $this->connection('zero-byte', idleSeconds: 3.0);
        $this->open($connection);

        self::assertSame('{"n":1004}', $connection->read(5));
        self::assertSame('{"n":1005}', $connection->read(5));
    }
}
