<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live\Protocol;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Live\Protocol\ConnectReply;
use WhiteMarket\Sdk\Live\Protocol\Disconnect;
use WhiteMarket\Sdk\Live\Protocol\MessageDecoder;
use WhiteMarket\Sdk\Live\Protocol\OtherMessage;
use WhiteMarket\Sdk\Live\Protocol\Ping;
use WhiteMarket\Sdk\Live\Protocol\Publication;
use WhiteMarket\Sdk\Live\Protocol\Reply;
use WhiteMarket\Sdk\Live\Protocol\ReplyError;
use WhiteMarket\Sdk\Live\Protocol\Unsubscribe;
use WhiteMarket\Sdk\Live\Protocol\WireJson;
use WhiteMarket\Sdk\Tests\Support\LiveFixture;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(MessageDecoder::class)]
#[CoversClass(WireJson::class)]
#[CoversClass(Publication::class)]
final class MessageDecoderTest extends TestCase
{
    #[Test]
    public function decodesAnEmptyObjectAsAPing(): void
    {
        self::assertInstanceOf(Ping::class, MessageDecoder::decode('{}'));
        self::assertInstanceOf(Ping::class, MessageDecoder::decode(" {} \n"));
        self::assertInstanceOf(Ping::class, MessageDecoder::decode('{"push":{}}'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function publicationEnvelopes(): iterable
    {
        yield 'SSE, unwrapped' => ['sse_product_added.json'];
        yield 'WS, push-wrapped' => ['ws_product_added.json'];
    }

    #[Test]
    #[DataProvider('publicationEnvelopes')]
    public function decodesAPublicationFromBothTransports(string $fixture): void
    {
        $publication = MessageDecoder::decode(LiveFixture::raw($fixture));

        self::assertInstanceOf(Publication::class, $publication);
        self::assertSame('market_products_updates', $publication->channel);
        self::assertIsString($publication->data['message'] ?? null);
        self::assertStringContainsString('"market_product_added"', $publication->data['message']);
    }

    #[Test]
    public function keepsAnObjectMessageAsAnArray(): void
    {
        $publication = MessageDecoder::decode(LiveFixture::raw('ws_product_added_object_message.json'));

        self::assertInstanceOf(Publication::class, $publication);
        self::assertIsArray($publication->data['message'] ?? null);
        self::assertSame('market_product_added', $publication->data['message']['type'] ?? null);
    }

    #[Test]
    public function keepsIntegersBeyondThePlatformIntAsDigitStrings(): void
    {
        $publication = MessageDecoder::decode('{"channel":"c","pub":{"data":{"message":{"n":9223372036854775808,"m":18446744073709551615}}}}');

        self::assertInstanceOf(Publication::class, $publication);
        self::assertSame(['n' => '9223372036854775808', 'm' => '18446744073709551615'], $publication->data['message'] ?? null);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function connectReplies(): iterable
    {
        yield 'SSE connect reply' => ['sse_connect_reply.json'];
        yield 'WS reply with the command id' => ['ws_connect_reply.json'];
    }

    #[Test]
    #[DataProvider('connectReplies')]
    public function decodesTheConnectReplyOfBothTransports(string $fixture): void
    {
        $reply = MessageDecoder::decode(LiveFixture::raw($fixture));

        self::assertInstanceOf(ConnectReply::class, $reply);
        self::assertSame(25, $reply->ping);
        self::assertSame(86400, $reply->ttl);
        self::assertTrue($reply->expires);
        self::assertContains('broadcast', $reply->subs);
        self::assertContains('#42', $reply->subs);
    }

    #[Test]
    public function connectReplyDifferencesBetweenTheTransports(): void
    {
        $sse = MessageDecoder::decode(LiveFixture::raw('sse_connect_reply.json'));
        $ws = MessageDecoder::decode(LiveFixture::raw('ws_connect_reply.json'));

        self::assertInstanceOf(ConnectReply::class, $sse);
        self::assertInstanceOf(ConnectReply::class, $ws);
        self::assertFalse($sse->pong, 'SSE has no pong');
        self::assertTrue($ws->pong);
        self::assertSame(['broadcast', '#42', 'market_products_updates'], $sse->subs);
    }

    #[Test]
    public function aMinimalConnectReplyHasDefaults(): void
    {
        $reply = MessageDecoder::decode('{"connect":{}}');

        $this->assertValueEquals(new ConnectReply(null, false, null, false, []), $reply);
    }

    #[Test]
    public function aNumericChannelNameStaysAString(): void
    {
        $reply = MessageDecoder::decode('{"connect":{"subs":{"123":{}}}}');

        self::assertInstanceOf(ConnectReply::class, $reply);
        self::assertSame(['123'], $reply->subs);
    }

    /**
     * @return iterable<string, array{string, int, ReplyError|null}>
     */
    public static function replies(): iterable
    {
        yield 'subscribe ok' => ['{"id":2,"subscribe":{}}', 2, null];
        yield 'subscribe with a result' => ['{"id":3,"subscribe":{"recoverable":false}}', 3, null];
        yield 'empty reply' => ['{"id":4}', 4, null];
        yield 'already subscribed' => [
            '{"id":2,"error":{"code":105,"message":"already subscribed","temporary":false}}',
            2,
            new ReplyError(105, 'already subscribed', false),
        ];
        yield 'temporary error without a message' => ['{"id":5,"error":{"code":100,"temporary":true}}', 5, new ReplyError(100, '', true)];
    }

    #[Test]
    #[DataProvider('replies')]
    public function decodesCommandReplies(string $raw, int $id, ?ReplyError $error): void
    {
        $this->assertValueEquals(new Reply($id, $error), MessageDecoder::decode($raw));
    }

    #[Test]
    public function decodesDisconnectAndUnsubscribePushes(): void
    {
        $this->assertValueEquals(
            new Disconnect(3009, 'shutdown'),
            MessageDecoder::decode('{"push":{"channel":"","disconnect":{"code":3009,"reason":"shutdown"}}}'),
        );
        $this->assertValueEquals(
            new Disconnect(3000, ''),
            MessageDecoder::decode('{"disconnect":{"code":3000}}'),
        );
        $this->assertValueEquals(
            new Unsubscribe('market_products_updates', 2500),
            MessageDecoder::decode('{"push":{"channel":"market_products_updates","unsubscribe":{"code":2500,"reason":"server unsubscribe"}}}'),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherMessages(): iterable
    {
        yield 'join push' => ['{"push":{"channel":"c","join":{"info":{}}}}'];
        yield 'leave push' => ['{"push":{"channel":"c","leave":{"info":{}}}}'];
        yield 'message push' => ['{"push":{"channel":"c","message":{"data":{"a":1}}}}'];
        yield 'refresh push' => ['{"push":{"refresh":{"expires":true,"ttl":60}}}'];
        yield 'unknown push kind with a channel' => ['{"push":{"channel":"c","future_kind":[1]}}'];
        yield 'unknown top-level key' => ['{"hello":1}'];
        yield 'unknown top-level kind with a channel (SSE)' => ['{"channel":"c","join":{}}'];
    }

    #[Test]
    #[DataProvider('otherMessages')]
    public function aWellFormedMessageOfAnUnmodelledKindIsOtherMessageNotAnError(string $raw): void
    {
        self::assertInstanceOf(OtherMessage::class, MessageDecoder::decode($raw));
    }

    #[Test]
    public function aReplyWithSeveralResultKeysIsStillAReply(): void
    {
        $this->assertValueEquals(new Reply(1, null), MessageDecoder::decode('{"id":1,"subscribe":{},"future":{"a":1}}'));
    }

    #[Test]
    public function aSuccessReplyPayloadIsNotInspected(): void
    {
        $this->assertValueEquals(new Reply(2, null), MessageDecoder::decode('{"id":2,"subscribe":"x"}'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenMessages(): iterable
    {
        yield 'not JSON' => ['not json at all', 'live'];
        yield 'empty string' => ['', 'live'];
        yield 'truncated JSON' => ['{"channel":"c","pub":', 'live'];
        yield 'a list' => ['[1,2]', 'live'];
        yield 'an empty list' => ['[]', 'live'];
        yield 'a scalar' => ['5', 'live'];
        yield 'null' => ['null', 'live'];
        yield 'push not an object' => ['{"push":5}', 'live.push'];
        yield 'pub without a channel' => ['{"pub":{"data":{}}}', 'live.channel'];
        yield 'pub channel a number' => ['{"channel":5,"pub":{"data":{}}}', 'live.channel'];
        yield 'pub not an object' => ['{"channel":"c","pub":"x"}', 'live.pub'];
        yield 'pub without data' => ['{"channel":"c","pub":{}}', 'live.pub.data'];
        yield 'pub data a list' => ['{"channel":"c","pub":{"data":[1]}}', 'live.pub.data'];
        yield 'reply id a string' => ['{"id":"1","subscribe":{}}', 'live.id'];
        yield 'error without a code' => ['{"id":1,"error":{"message":"x"}}', 'live.error.code'];
        yield 'error code a string' => ['{"id":1,"error":{"code":"105"}}', 'live.error.code'];
        yield 'error message a number' => ['{"id":1,"error":{"code":1,"message":5}}', 'live.error.message'];
        yield 'error temporary a string' => ['{"id":1,"error":{"code":1,"temporary":"yes"}}', 'live.error.temporary'];
        yield 'connect not an object' => ['{"connect":5}', 'live.connect'];
        yield 'connect ping a string' => ['{"connect":{"ping":"25"}}', 'live.connect.ping'];
        yield 'connect ttl a float' => ['{"connect":{"ttl":1.5}}', 'live.connect.ttl'];
        yield 'connect pong a number' => ['{"connect":{"pong":1}}', 'live.connect.pong'];
        yield 'connect expires a string' => ['{"connect":{"expires":"true"}}', 'live.connect.expires'];
        yield 'connect subs a scalar' => ['{"connect":{"subs":5}}', 'live.connect.subs'];
        yield 'disconnect without a code' => ['{"disconnect":{"reason":"x"}}', 'live.disconnect.code'];
        yield 'disconnect reason a number' => ['{"disconnect":{"code":3000,"reason":5}}', 'live.disconnect.reason'];
        yield 'disconnect not an object' => ['{"disconnect":"x"}', 'live.disconnect'];
        yield 'unsubscribe without a channel' => ['{"unsubscribe":{"code":2500}}', 'live.channel'];
        yield 'unsubscribe without a code' => ['{"channel":"c","unsubscribe":{}}', 'live.unsubscribe.code'];
    }

    #[Test]
    #[DataProvider('brokenMessages')]
    public function rejectsAMessageOfAnUnknownShape(string $raw, string $path): void
    {
        $e = TraceArguments::caught(static fn(): mixed => MessageDecoder::decode($raw));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
    }

    #[Test]
    public function aFailureMessageNeverHoldsServerBytes(): void
    {
        $e = TraceArguments::caught(static fn(): mixed => MessageDecoder::decode('{"channel":"SERVER-CHANNEL-MARK","pub":"SERVER-PUB-MARK"}'));
        $unknown = TraceArguments::caught(static fn(): mixed => MessageDecoder::decode('{"disconnect":{"code":"SERVER-VALUE-MARK"}}'));
        $badJson = TraceArguments::caught(static fn(): mixed => MessageDecoder::decode('SERVER-JSON-MARK'));

        foreach ([$e, $unknown, $badJson] as $failure) {
            self::assertStringNotContainsString('MARK', $failure->getMessage());
            self::assertNotInstanceOf(\Throwable::class, $failure->getPrevious());
        }
    }

    #[Test]
    public function acceptsNestingUpToDepth64AndRejectsDeeper(): void
    {
        // pub.data.message sits at depth 4 of the envelope; the rest is nesting inside the message.
        $nest = static fn(int $levels): string => str_repeat('[', $levels) . str_repeat(']', $levels);

        self::assertInstanceOf(Publication::class, MessageDecoder::decode('{"channel":"c","pub":{"data":{"message":' . $nest(60) . '}}}'));
        $e = TraceArguments::caught(static fn(): mixed => MessageDecoder::decode('{"channel":"c","pub":{"data":{"message":' . $nest(61) . '}}}'));
        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringContainsString('64', $e->getMessage());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function serverTexts(): iterable
    {
        yield 'short ascii stays' => ['shutdown', 'shutdown'];
        yield 'control, non-ASCII and the cut' => ["a\nb\u{e9}" . str_repeat('c', 200), 'a?b??' . str_repeat('c', 123)];
    }

    #[Test]
    #[DataProvider('serverTexts')]
    public function keepsServerTextThroughServerTextPrintable(string $text, string $expected): void
    {
        $disconnect = MessageDecoder::decode(json_encode(['disconnect' => ['code' => 3000, 'reason' => $text]], \JSON_THROW_ON_ERROR));
        $reply = MessageDecoder::decode(json_encode(['id' => 1, 'error' => ['code' => 100, 'message' => $text]], \JSON_THROW_ON_ERROR));

        self::assertInstanceOf(Disconnect::class, $disconnect);
        self::assertSame($expected, $disconnect->reason);
        self::assertInstanceOf(Reply::class, $reply);
        self::assertSame($expected, $reply->error?->message);
    }

    /**
     * Value equality for readonly objects (`assertSame` would compare identities; php-cs-fixer rewrites `assertEquals`).
     */
    private function assertValueEquals(mixed $expected, mixed $actual): void
    {
        self::assertThat($actual, self::equalTo($expected));
    }
}
