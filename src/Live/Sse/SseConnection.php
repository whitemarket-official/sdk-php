<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\Sse;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\ConnectInfo;
use WhiteMarket\Sdk\Live\Connection;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\Protocol\ConnectReply;
use WhiteMarket\Sdk\Live\Protocol\Disconnect;
use WhiteMarket\Sdk\Live\Protocol\MessageDecoder;
use WhiteMarket\Sdk\Live\ServerClosed;
use WhiteMarket\Sdk\Transport\BodyReader;
use WhiteMarket\Sdk\Transport\ClientFailure;
use WhiteMarket\Sdk\Transport\ClientResponse;
use WhiteMarket\Sdk\Transport\ClientStream;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\Failure;
use WhiteMarket\Sdk\Transport\RetryAfter;
use WhiteMarket\Sdk\Transport\RetryLoop;
use WhiteMarket\Sdk\Transport\SystemClock;

/**
 * The live connection over Server-Sent Events (WHITEMARKET_API.md 9): one GET whose query carries the token and the
 * channels, answered with an endless `text/event-stream`. SSE cannot send, so {@see pong()} does nothing and
 * {@see subscribe()} is refused; the session reconnects with a new channel list.
 *
 * Reading uses slices: the HTTP client is expected to have a read timeout (the default live client has, see
 * {@see \WhiteMarket\Sdk\Transport\GuzzleClientFactory::createForLive()}), a slice that expires without data is
 * {@see ClientStream::readSlice()} returning '' and {@see read()} checks its deadline between slices. A read can
 * therefore overrun its deadline by up to one slice. With a client without a read timeout a read blocks until data
 * comes. The request asks for `Accept-Encoding: identity`, so a compressed response is a protocol error.
 *
 * The connect reply is not always the first event: the server sends publications before it. {@see open()} keeps every
 * message that comes before the reply as raw text (bounded by its total size, not by a count) and {@see read()}
 * returns them first, in order, as if they had come after the reply. {@see close()} drops the undelivered ones.
 *
 * The token travels in the URL: the URL, the request and the client's exceptions never reach a message, a log or a
 * chained exception (the exception of the client is dropped, as everywhere in the transport).
 *
 * @internal
 */
final class SseConnection implements Connection
{
    private const SLICE_BYTES = 8192;

    /**
     * The live buffer budget, SDK_SPEC 3.11: the total text of the messages kept before the connect reply.
     */
    private const EARLY_TEXT_BYTES = 64 * 1024 * 1024;

    private ?ClientResponse $response = null;

    private ?ClientStream $body = null;

    private ?SseLineParser $parser = null;

    /**
     * @var \SplQueue<string> raw messages that came before the connect reply, not yet returned by {@see read()}
     */
    private \SplQueue $early;

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly string $baseUrl,
        private readonly LiveOptions $options,
        private readonly Clock $clock = new SystemClock(),
        private readonly int $earlyTextBytes = self::EARLY_TEXT_BYTES,
    ) {
        $this->early = new \SplQueue();
    }

    /**
     * @throws HttpException on a non-2xx status (the session decides what to do with it)
     * @throws TransportException
     */
    public function open(#[\SensitiveParameter] Secret $token, array $channels, float $timeout): ConnectInfo
    {
        $this->close();
        $until = microtime(true) + $timeout;
        $response = $this->send($token, $channels);
        $this->response = $response;

        try {
            $status = $response->statusCode();
            if ($status < 200 || $status > 299) {
                throw $this->statusFailure($response, $status);
            }
            $this->assertIdentityEncoding($response);
            $this->body = $response->body();
            $this->parser = new SseLineParser($this->options->maxMessageBytes);

            return $this->awaitConnectReply($until);
        } catch (\Throwable $e) {
            $this->close();

            throw $e;
        }
    }

    /**
     * Reads until the connect reply; every other message is kept for {@see read()}.
     *
     * @throws ServerClosed when the server disconnects before the reply
     * @throws TransportException
     */
    private function awaitConnectReply(float $until): ConnectInfo
    {
        $early = new \SplQueue();
        $keptBytes = 0;
        while (true) {
            $raw = $this->read(max(0.0, $until - microtime(true)));
            if ($raw === null) {
                throw new TransportException('The live connection sent no connect reply in time.');
            }

            try {
                $message = MessageDecoder::decode($raw);
            } catch (DecodeException) {
                // Reported by the session when it reads the message, as for any message after the reply.
                $message = null;
            }
            if ($message instanceof ConnectReply) {
                $this->early = $early;

                return ConnectInfo::fromReply($message);
            }
            if ($message instanceof Disconnect) {
                throw new ServerClosed($message->code, $message->reason);
            }

            $keptBytes += \strlen($raw);
            if ($keptBytes > $this->earlyTextBytes) {
                throw new TransportException('The live stream sent too much data before the connect reply.');
            }
            $early->enqueue($raw);
        }
    }

    /**
     * @throws TransportException when the stream ended or broke (any end of this endless stream is a broken
     *                            connection; a partial event is dropped) or an event exceeds the size cap
     */
    public function read(float $timeout): ?string
    {
        $until = microtime(true) + $timeout;
        $body = $this->body;
        $parser = $this->parser;
        if (!$body instanceof ClientStream || !$parser instanceof SseLineParser) {
            throw new TransportException('The live connection is not open.');
        }
        if (!$this->early->isEmpty()) {
            return $this->early->dequeue();
        }

        while (true) {
            $message = $parser->next();
            if ($message !== null) {
                return $message;
            }
            if (microtime(true) >= $until) {
                return null;
            }

            $chunk = $body->readSlice(self::SLICE_BYTES);
            if ($chunk === '') {
                if ($body->eof()) {
                    throw new TransportException('The live stream ended.');
                }

                continue;
            }
            $parser->feed($chunk);
        }
    }

    public function subscribesAfterConnect(): bool
    {
        return false;
    }

    public function subscribe(string $channel): void
    {
        // A guard: the session checks subscribesAfterConnect() and reconnects instead.
        throw new \LogicException('SSE subscribes at connect time; reconnect with the new channel list.');
    }

    public function pong(): void {}

    /**
     * The open body stream keeps the request URL, token included, in its metadata: dumps show the state only.
     *
     * @return array{open: bool}
     */
    public function __debugInfo(): array
    {
        return ['open' => $this->body instanceof ClientStream];
    }

    public function close(): void
    {
        $response = $this->response;
        $this->response = null;
        $this->body = null;
        $this->parser = null;
        $this->early = new \SplQueue();
        if (!$response instanceof ClientResponse) {
            return;
        }

        try {
            $response->discard();
        } catch (TransportException) {
            // The stream is already broken or gone; there is nothing left to release and the caller closes to stop.
        }
    }

    /**
     * @param list<string> $channels
     *
     * @throws TransportException
     */
    private function send(#[\SensitiveParameter] Secret $token, array $channels): ClientResponse
    {
        // Encoding the token (an internal `json_encode` frame keeps it as an argument) and parsing the URL (the
        // factory's message quotes it) can fail with the token inside: such a failure is dropped like a client one.
        try {
            $request = $this->request($token, $channels);
        } catch (\Throwable $e) {
            throw Failure::named('The live HTTP request could not be built', $e);
        }
        try {
            return RetryLoop::sendVia($this->client, $request);
        } catch (ClientFailure $e) {
            throw Failure::named('The live HTTP request failed', $e);
        }
    }

    /**
     * @param list<string> $channels
     */
    private function request(#[\SensitiveParameter] Secret $token, array $channels): RequestInterface
    {
        $subs = [];
        foreach ($channels as $channel) {
            $subs[$channel] = [];
        }
        // Forced objects: "subs" and every channel option are JSON objects, also when empty.
        $connect = json_encode(['token' => $token->reveal(), 'subs' => $subs], \JSON_THROW_ON_ERROR | \JSON_FORCE_OBJECT);

        return $this->requestFactory->createRequest('GET', self::url($this->baseUrl, $this->options->ssePath, $connect))
            ->withHeader('Accept', 'text/event-stream')
            ->withHeader('Accept-Encoding', 'identity')
            ->withHeader('User-Agent', $this->options->userAgent());
    }

    /**
     * The URL of one connect: the endpoint with the connect command (JSON) in `cf_connect`.
     */
    public static function url(string $baseUrl, string $ssePath, #[\SensitiveParameter] string $connect): string
    {
        return rtrim($baseUrl, '/') . $ssePath . '?cf_connect=' . rawurlencode($connect);
    }

    private function statusFailure(#[\SensitiveParameter] ClientResponse $response, int $status): HttpException
    {
        $retryAfter = RetryAfter::seconds($response->headerLine('Retry-After'), $this->clock->now());

        // The status matters more than the body, so a body that cannot be read leaves an empty excerpt.
        try {
            $excerpt = BodyReader::errorExcerpt($response, HttpException::BODY_EXCERPT_BYTES);
        } catch (TransportException) {
            $excerpt = '';
        }

        return HttpException::fromStatus($status, $excerpt, $retryAfter);
    }

    /**
     * @throws TransportException
     */
    private function assertIdentityEncoding(#[\SensitiveParameter] ClientResponse $response): void
    {
        if (!$response->hasHeader('Content-Encoding')) {
            return;
        }
        $encoding = strtolower(trim($response->headerLine('Content-Encoding')));
        if ($encoding !== '' && $encoding !== 'identity') {
            throw new TransportException('The live stream has a content encoding although none was accepted.');
        }
    }
}
