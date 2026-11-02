<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Protocol;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\ServerText;
use WhiteMarket\Sdk\GraphQL\Decode;

/**
 * One raw Centrifugo JSON message (one WS line, one SSE `data:` line) to a protocol value. Accepts the WS push wrapper
 * (`{"push":{"channel":..,"pub":..}}`) and the unwrapped SSE push (`{"channel":..,"pub":..}`), the SSE connect reply
 * (`{"connect":{..}}`) and the WS one (`{"id":1,"connect":{..}}`), command replies, and the empty ping `{}`
 * (WHITEMARKET_API.md 9). A well-formed message of a kind we do not model (`join`, `leave`, `message`, `refresh`, a newer
 * server's keys) is an {@see OtherMessage}, never an error (CLAUDE.md rule 5). A {@see DecodeException} is for text that is
 * not JSON, not an object or nested too deep, and for a known kind with a malformed field.
 * Failure messages hold a path and a reason, never a byte of the message; server text kept in the values (reasons,
 * error messages) is cut by {@see ServerText::printable()}.
 *
 * @internal used by the live session
 */
final class MessageDecoder
{
    private const PATH = 'live';

    private function __construct() {}

    /**
     * @throws DecodeException
     */
    public static function decode(#[\SensitiveParameter] string $raw): Ping|Reply|Publication|Disconnect|Unsubscribe|ConnectReply|OtherMessage
    {
        $decoded = WireJson::decode($raw, self::PATH);
        // `[]` decodes like `{}`; only a real object is a message.
        $message = str_starts_with(ltrim($raw), '{') ? WireJson::objectOrNull($decoded) : null;
        if ($message === null) {
            throw new DecodeException(self::PATH, 'not an object');
        }
        $path = self::PATH;
        if (\array_key_exists('push', $message)) {
            $path .= '.push';
            $message = Decode::object($message['push'], $path);
        }

        if ($message === []) {
            return new Ping();
        }
        if (\array_key_exists('connect', $message)) {
            return self::connectReply($message['connect'], $path . '.connect');
        }
        if (\array_key_exists('id', $message)) {
            return self::reply($message, $path);
        }
        if (\array_key_exists('pub', $message)) {
            return self::publication($message['pub'], $message, $path);
        }
        if (\array_key_exists('disconnect', $message)) {
            $at = $path . '.disconnect';
            $disconnect = Decode::object($message['disconnect'], $at);
            $optionalString = Decode::nullableOf(Decode::string(...));

            return new Disconnect(
                Decode::int($disconnect['code'] ?? null, $at . '.code'),
                ServerText::printable($optionalString($disconnect['reason'] ?? null, $at . '.reason') ?? ''),
            );
        }
        if (\array_key_exists('unsubscribe', $message)) {
            $at = $path . '.unsubscribe';
            $unsubscribe = Decode::object($message['unsubscribe'], $at);

            return new Unsubscribe(
                Decode::string($message['channel'] ?? null, $path . '.channel'),
                Decode::int($unsubscribe['code'] ?? null, $at . '.code'),
            );
        }

        return new OtherMessage();
    }

    /**
     * @param array<mixed> $message
     *
     * @throws DecodeException
     */
    private static function publication(#[\SensitiveParameter] mixed $pubValue, #[\SensitiveParameter] array $message, string $path): Publication
    {
        $pub = Decode::object($pubValue, $path . '.pub');
        $data = Decode::object($pub['data'] ?? null, $path . '.pub.data');

        return new Publication(Decode::string($message['channel'] ?? null, $path . '.channel'), $data);
    }

    /**
     * @param array<mixed> $message
     *
     * @throws DecodeException
     */
    private static function reply(#[\SensitiveParameter] array $message, string $path): Reply
    {
        $id = Decode::int($message['id'] ?? null, $path . '.id');
        if (\array_key_exists('error', $message)) {
            $at = $path . '.error';
            $error = Decode::object($message['error'], $at);
            $optionalString = Decode::nullableOf(Decode::string(...));

            return new Reply($id, new ReplyError(
                Decode::int($error['code'] ?? null, $at . '.code'),
                ServerText::printable($optionalString($error['message'] ?? null, $at . '.message') ?? ''),
                Decode::bool($error['temporary'] ?? false, $at . '.temporary'),
            ));
        }

        return new Reply($id, null);
    }

    /**
     * @throws DecodeException
     */
    private static function connectReply(#[\SensitiveParameter] mixed $value, string $path): ConnectReply
    {
        $connect = Decode::object($value, $path);
        $subs = Decode::object($connect['subs'] ?? [], $path . '.subs');
        $optionalInt = Decode::nullableOf(Decode::int(...));
        $channels = [];
        foreach (array_keys($subs) as $channel) {
            $channels[] = (string) $channel;
        }

        return new ConnectReply(
            $optionalInt($connect['ping'] ?? null, $path . '.ping'),
            Decode::bool($connect['pong'] ?? false, $path . '.pong'),
            $optionalInt($connect['ttl'] ?? null, $path . '.ttl'),
            Decode::bool($connect['expires'] ?? false, $path . '.expires'),
            $channels,
        );
    }
}
