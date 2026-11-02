<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\Protocol\ConnectReply;
use WhiteMarket\Sdk\Live\Protocol\MessageDecoder;
use WhiteMarket\Sdk\Live\Protocol\Reply;
use WhiteMarket\Sdk\Live\Protocol\ReplyError;
use WhiteMarket\Sdk\Transport\Failure;

/**
 * What the session needs from a connect reply: the server ping interval in seconds, whether pings must be answered,
 * the token lifetime in seconds and the channels the server subscribed already.
 *
 * @internal
 */
final readonly class ConnectInfo
{
    public const DEFAULT_PING_SECONDS = 25;

    /** Upper bound for a server ping interval, so a hostile value cannot disable the idle deadline. */
    public const MAX_PING_SECONDS = 300;

    /** Seconds past the server ping interval after which a silent connection counts as dead (like centrifuge-js). */
    public const IDLE_GRACE_SECONDS = 10;

    /**
     * @param list<string> $subs
     */
    public function __construct(
        public int $ping,
        public bool $pong,
        public ?int $ttl,
        public array $subs,
    ) {}

    /**
     * The info of the first message of a connection; null (no message before the deadline) is a failure.
     * `$connectCommandId` is the id of the connect command on a transport that sends one (the WebSocket): an error
     * reply with that id is a rejection.
     *
     * @throws TransportException there is no message, or it is not valid or not a connect reply
     * @throws ConnectRejected the server answered the connect command with an error
     */
    public static function fromFirstMessage(#[\SensitiveParameter] ?string $raw, ?int $connectCommandId = null): self
    {
        if ($raw === null) {
            throw new TransportException('The live connection sent no connect reply in time.');
        }
        try {
            $message = MessageDecoder::decode($raw);
        } catch (DecodeException $e) {
            throw Failure::named('The first live message is not a valid message', $e);
        }
        if ($connectCommandId !== null && $message instanceof Reply && $message->id === $connectCommandId && $message->error instanceof ReplyError) {
            throw new ConnectRejected($message->error->code, $message->error->temporary);
        }
        if (!$message instanceof ConnectReply) {
            throw new TransportException('The first live message is not a connect reply.');
        }

        return self::fromReply($message);
    }

    /**
     * Seconds without any message after which the connection counts as dead: the ping interval plus the grace.
     */
    public function idleSeconds(): float
    {
        return (float) ($this->ping + self::IDLE_GRACE_SECONDS);
    }

    public static function fromReply(ConnectReply $reply): self
    {
        return new self(
            $reply->ping !== null && $reply->ping > 0 ? min($reply->ping, self::MAX_PING_SECONDS) : self::DEFAULT_PING_SECONDS,
            $reply->pong,
            $reply->expires ? $reply->ttl : null,
            $reply->subs,
        );
    }
}
