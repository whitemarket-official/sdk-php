<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\ConnectRejected;
use WhiteMarket\Sdk\Live\Protocol\ConnectReply;
use WhiteMarket\Sdk\Tests\Support\LiveFixture;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(ConnectInfo::class)]
#[CoversClass(ConnectRejected::class)]
final class ConnectInfoTest extends TestCase
{
    #[Test]
    public function mapsEveryFieldOfTheReply(): void
    {
        $info = ConnectInfo::fromReply(new ConnectReply(30, true, 86400, true, ['a', 'b']));

        self::assertSame([30, true, 86400, ['a', 'b']], [$info->ping, $info->pong, $info->ttl, $info->subs]);
    }

    #[Test]
    public function aTtlWithoutTheExpiresFlagIsDropped(): void
    {
        self::assertNull(ConnectInfo::fromReply(new ConnectReply(25, false, 10, false, []))->ttl);
    }

    #[Test]
    public function idleSecondsArePingPlusTheGrace(): void
    {
        self::assertSame(40.0, ConnectInfo::fromReply(new ConnectReply(30, false, null, false, []))->idleSeconds());
        self::assertSame(35.0, ConnectInfo::fromReply(new ConnectReply(null, false, null, false, []))->idleSeconds());
    }

    #[Test]
    public function clampsAHugePingSoTheIdleDeadlineStaysBounded(): void
    {
        $info = ConnectInfo::fromReply(new ConnectReply(\PHP_INT_MAX, false, null, false, []));

        self::assertSame(300, $info->ping);
        self::assertSame(310.0, $info->idleSeconds());
        self::assertSame(300, ConnectInfo::fromReply(new ConnectReply(300, false, null, false, []))->ping);
        self::assertSame(300, ConnectInfo::fromReply(new ConnectReply(301, false, null, false, []))->ping);
    }

    #[Test]
    public function noFirstMessageIsATransportError(): void
    {
        $e = TraceArguments::caught(static fn(): ConnectInfo => ConnectInfo::fromFirstMessage(null));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('The live connection sent no connect reply in time.', $e->getMessage());
    }

    #[Test]
    public function defaultsThePingTo25SecondsWhenAbsent(): void
    {
        $info = ConnectInfo::fromReply(new ConnectReply(null, false, null, false, []));

        self::assertSame([25, false, null, []], [$info->ping, $info->pong, $info->ttl, $info->subs]);
    }

    #[Test]
    public function defaultsANonPositivePing(): void
    {
        self::assertSame(25, ConnectInfo::fromReply(new ConnectReply(0, false, null, false, []))->ping);
    }

    #[Test]
    public function theFirstMessageOfEitherTransportIsAConnectReply(): void
    {
        $sse = ConnectInfo::fromFirstMessage(LiveFixture::raw('sse_connect_reply.json'));
        $ws = ConnectInfo::fromFirstMessage(LiveFixture::raw('ws_connect_reply.json'), 1);

        self::assertFalse($sse->pong);
        self::assertTrue($ws->pong);
    }

    #[Test]
    public function anErrorReplyWithTheConnectCommandIdIsARejection(): void
    {
        $rejected = TraceArguments::caught(static fn(): ConnectInfo => ConnectInfo::fromFirstMessage('{"id":1,"error":{"code":109,"message":"token expired","temporary":true}}', 1));

        self::assertInstanceOf(ConnectRejected::class, $rejected);
        self::assertSame(109, $rejected->errorCode);
        self::assertTrue($rejected->temporary);
    }

    #[Test]
    public function anErrorReplyIsNotARejectionWithoutOrWithAnotherCommandId(): void
    {
        foreach ([null, 7] as $id) {
            $e = TraceArguments::caught(static fn(): ConnectInfo => ConnectInfo::fromFirstMessage('{"id":1,"error":{"code":109}}', $id));

            self::assertInstanceOf(TransportException::class, $e);
            self::assertSame('The first live message is not a connect reply.', $e->getMessage());
        }
    }

    #[Test]
    public function aMessageThatIsNotJsonIsATransportErrorWithoutServerBytes(): void
    {
        $e = TraceArguments::caught(static fn(): ConnectInfo => ConnectInfo::fromFirstMessage('SERVER-MARK'));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('The first live message is not a valid message (WhiteMarket\Sdk\Errors\DecodeException).', $e->getMessage());
        self::assertNotInstanceOf(\Throwable::class, $e->getPrevious());
    }

    #[Test]
    public function aPublicationIsNotAConnectReply(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('The first live message is not a connect reply.');
        ConnectInfo::fromFirstMessage(LiveFixture::raw('sse_product_added.json'));
    }
}
