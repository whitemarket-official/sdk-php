<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\WebSocket;

use Phrity\Net\Context;
use WebSocket\Client;
use WebSocket\Configuration;
use WebSocket\Exception\ConnectionTimeoutException;
use WebSocket\Message\Binary;
use WebSocket\Message\Close;
use WebSocket\Message\Ping;
use WebSocket\Message\Pong;
use WebSocket\Message\Text;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\Connection;
use WhiteMarket\Sdk\Live\ConnectRejected;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\ServerClosed;
use WhiteMarket\Sdk\Transport\Failure;

/**
 * The live connection over a WebSocket (WHITEMARKET_API.md 9) with the Centrifugo JSON protocol, on `phrity/websocket`.
 *
 * phrity fixes the socket timeout when it connects, so that timeout is the idle deadline (the server ping interval plus
 * 10 s, like centrifuge-js). {@see read()} waits for data with its own `stream_select` on the socket that
 * {@see CapturingStreamFactory} kept, and calls `receive()` only when the socket is readable, so a deadline shorter
 * than the idle timeout works. `stream_select` sees the bytes PHP already buffered; the unread-bytes check before it is
 * a cheap net. A socket that is readable with only part of a frame makes `receive()` block up to the idle timeout (the
 * read may then overrun its deadline); that timeout means the connection is broken. The handshake after the TCP
 * connect is bounded by the open deadline plus the idle seconds.
 *
 * Reads are bounded overall by {@see DeadlineSocketStream}: the deadline of {@see read()} (or of {@see open()}) plus the
 * idle seconds, checked before every socket read. A server trickling bytes inside the socket timeout therefore ends the
 * read at most one socket-read gap past that bound with a {@see TransportException} (the partial frame is dropped and
 * the session reconnects with backoff), instead of holding it for payload length times the gap.
 *
 * Pings: phrity runs without middleware, so a protocol-level ping frame is answered here with a pong frame, and the
 * Centrifugo ping (`{}`) reaches the session as an ordinary message, which answers with {@see pong()}. A close frame is
 * a {@see ServerClosed}; an error reply to the connect command is a {@see ConnectRejected}. Replies to subscribe
 * commands are not awaited in {@see open()}: they come through {@see read()}.
 *
 * The token appears only in the connect command. Exceptions of phrity are never chained and their messages never
 * forwarded (they contain the full URI); the SDK exception names the class only.
 *
 * @internal
 */
final class WebSocketConnection implements Connection
{
    private const SDK_NAME = 'whitemarket-sdk-php';

    private const CONNECT_ID = 1;

    /** Longest single stream_select wait; read() returns null after it and the session asks again. */
    private const MAX_WAIT_SECONDS = 3600.0;

    private ?Client $client = null;

    private ?CapturingStreamFactory $factory = null;

    /** @var list<string> */
    private array $queue = [];

    private int $nextId = self::CONNECT_ID + 1;

    private bool $reading = false;

    private float $idle = ConnectInfo::DEFAULT_PING_SECONDS + ConnectInfo::IDLE_GRACE_SECONDS;

    public function __construct(
        private readonly string $baseUrl,
        private readonly LiveOptions $options,
        /** Seconds a read may run past its deadline; null: the server's idle limit (tests drive it small). */
        private readonly ?float $idleSeconds = null,
    ) {}

    public function open(#[\SensitiveParameter] Secret $token, array $channels, float $timeout): ConnectInfo
    {
        $this->close();
        $until = microtime(true) + $timeout;
        $this->idle = $this->idleSeconds ?? ConnectInfo::DEFAULT_PING_SECONDS + ConnectInfo::IDLE_GRACE_SECONDS;

        try {
            $this->connect($until + $this->idle);
            $this->sendJson(['id' => self::CONNECT_ID, 'connect' => ['token' => $token->reveal(), 'name' => self::SDK_NAME]]);
            $info = ConnectInfo::fromFirstMessage($this->read(max(0.0, $until - microtime(true))), self::CONNECT_ID);
            $this->idle = $this->idleSeconds ?? $info->idleSeconds();
            foreach ($channels as $channel) {
                if (!\in_array($channel, $info->subs, true)) {
                    $this->subscribe($channel);
                }
            }

            return $info;
        } catch (\Throwable $e) {
            $this->close();

            throw $e;
        }
    }

    /**
     * @throws TransportException when the connection broke, a message exceeds the size cap or the server sent binary
     * @throws ServerClosed when the server sent a close frame
     */
    public function read(float $timeout): ?string
    {
        $until = microtime(true) + $timeout;
        $client = $this->client;
        if (!$client instanceof Client) {
            throw new TransportException('The live connection is not open.');
        }

        // One deadline for the whole call: a server flooding complete frames cannot push it forward frame by frame.
        $this->factory?->setDeadline($until + $this->idle);
        $this->reading = true;

        try {
            return $this->pump($until);
        } finally {
            $this->reading = false;
        }
    }

    /**
     * @throws TransportException
     * @throws ServerClosed
     */
    private function pump(float $until): ?string
    {
        // Not a parameter: a stack trace would carry the client, and its URI, in the arguments.
        $client = $this->client ?? throw new TransportException('The live connection is not open.');
        $frames = 0;
        while (true) {
            $message = array_shift($this->queue);
            if ($message !== null) {
                return $message;
            }
            // At least one frame is taken per call, so read(0) polls; after that the deadline wins over buffered data,
            // which stays buffered for the next call.
            if ($frames > 0 && microtime(true) >= $until) {
                return null;
            }
            if (!$this->waitReadable($until)) {
                return null;
            }

            try {
                $frame = $client->receive();
            } catch (\Throwable $e) {
                throw $e instanceof ConnectionTimeoutException || $e instanceof DeadlineExceeded ? $this->idleFailure() : Failure::named('The live WebSocket failed', $e);
            }
            ++$frames;

            if ($frame instanceof Text) {
                foreach (explode("\n", $frame->getContent()) as $part) {
                    if ($part !== '') {
                        $this->queue[] = $part;
                    }
                }
            } elseif ($frame instanceof Ping) {
                $this->sendFrame(new Pong($frame->getContent()));
            } elseif ($frame instanceof Close) {
                throw new ServerClosed($frame->getCloseStatus(), $frame->getContent());
            } elseif ($frame instanceof Binary) {
                throw new TransportException('The server sent a binary frame; the live protocol is text.');
            }
            // A pong frame answers nothing we sent and is ignored.
        }
    }

    public function subscribesAfterConnect(): bool
    {
        return true;
    }

    public function subscribe(string $channel): void
    {
        $this->sendJson(['id' => $this->nextId++, 'subscribe' => ['channel' => $channel]]);
    }

    public function pong(): void
    {
        $this->sendFrame(new Text('{}'));
    }

    public function close(): void
    {
        $client = $this->client;
        $this->client = null;
        $this->factory?->forget();
        $this->factory = null;
        $this->queue = [];
        $this->reading = false;
        $this->nextId = self::CONNECT_ID + 1;
        if (!$client instanceof Client) {
            return;
        }

        try {
            $client->disconnect();
        } catch (\Throwable) {
            // The socket is already broken or gone; there is nothing left to release and the caller closes to stop.
        }
    }

    /**
     * @throws TransportException
     */
    private function connect(float $deadline): void
    {
        try {
            $uri = $this->uri();
            $configuration = $this->configuration($uri);
            $factory = new CapturingStreamFactory($this->options->connectTimeout);
            $factory->setDeadline($deadline);
            $client = new Client($uri, $configuration, $factory);
            $client->addHeader('User-Agent', $this->options->userAgent());
        } catch (\Throwable $e) {
            // A malformed base URL: phrity quotes it in its message.
            throw Failure::named('The live WebSocket could not be set up', $e);
        }
        $this->factory = $factory;
        $this->client = $client;

        try {
            $client->connect();
        } catch (\Throwable $e) {
            throw Failure::named('The live WebSocket could not connect', $e);
        }
    }

    private function configuration(string $uri): Configuration
    {
        $host = parse_url($uri, \PHP_URL_HOST);

        return new Configuration(
            context: new Context(stream_context_create(['ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => \is_string($host) ? $host : '',
            ]])),
            timeout: $this->idle,
            maxReceivedFrameSize: max(1, $this->options->maxMessageBytes),
            maxReceivedMessageSize: max(1, $this->options->maxMessageBytes),
        );
    }

    private function uri(): string
    {
        $base = rtrim($this->baseUrl, '/');
        $base = match (true) {
            str_starts_with($base, 'https://') => 'wss://' . substr($base, 8),
            str_starts_with($base, 'http://') => 'ws://' . substr($base, 7),
            default => $base,
        };

        return $base . $this->options->wsPath;
    }

    /**
     * Waits until the socket has data or the deadline passes.
     *
     * @throws TransportException
     */
    private function waitReadable(float $deadline): bool
    {
        $resource = $this->factory?->resource();
        if ($resource === null) {
            throw new TransportException('The live connection is not open.');
        }
        if (stream_get_meta_data($resource)['unread_bytes'] > 0) {
            return true;
        }

        $remaining = min(self::MAX_WAIT_SECONDS, max(0.0, $deadline - microtime(true)));
        $seconds = (int) floor($remaining);
        $read = [$resource];
        $write = null;
        $except = null;
        // stream_select() warns and returns false on a broken socket or a signal; false is handled below (the session
        // reconnects).
        $ready = @stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));
        if ($ready === false) {
            throw new TransportException('Waiting for the live socket failed.');
        }

        return $ready > 0;
    }

    /**
     * @param array<string, mixed> $command
     *
     * @throws TransportException
     */
    private function sendJson(#[\SensitiveParameter] array $command): void
    {
        try {
            $json = json_encode($command, \JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            // The failing json_encode frame holds the command, with the token for the connect command: drop it.
            throw Failure::named('Sending on the live WebSocket failed', $e);
        }
        $this->sendFrame(new Text($json));
    }

    /**
     * @throws TransportException
     */
    private function sendFrame(#[\SensitiveParameter] Text|Pong $frame): void
    {
        $client = $this->client ?? throw new TransportException('The live connection is not open.');
        if (!$this->reading) {
            // A write has its own deadline; inside read() the read deadline already covers it.
            $this->factory?->setDeadline(microtime(true) + $this->idle);
        }
        try {
            $client->send($frame);
        } catch (\Throwable $e) {
            throw Failure::named('Sending on the live WebSocket failed', $e);
        }
    }

    /**
     * The idle timeout passed inside a partial frame: the connection is broken. The phrity exception is dropped, its
     * message holds the URI.
     */
    private function idleFailure(): TransportException
    {
        return new TransportException('The live connection was idle past its deadline.');
    }
}
