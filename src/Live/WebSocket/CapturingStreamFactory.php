<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\WebSocket;

use Phrity\Net\Context;
use Phrity\Net\SocketClient;
use Phrity\Net\SocketStream;
use Phrity\Net\StreamFactory;
use Psr\Http\Message\UriInterface;

/**
 * Keeps the {@see SocketStream} the phrity client connects with: `WebSocket\Client` holds its connection privately and
 * has no "data waiting" check, so {@see WebSocketConnection} selects on the raw socket itself. The TCP connect uses
 * `$connectTimeout`; the idle timeout the client sets later belongs to reads. The captured stream is a
 * {@see DeadlineSocketStream}: {@see setDeadline()} bounds every read of the handshake and of a frame.
 *
 * @internal
 */
final class CapturingStreamFactory extends StreamFactory
{
    private ?DeadlineSocketStream $captured = null;

    private ?float $deadline = null;

    public function __construct(private readonly float $connectTimeout)
    {
        parent::__construct();
    }

    public function createSocketClient(UriInterface $uri, ?Context $context = null): SocketClient
    {
        return new class ($uri, $context, $this, $this->connectTimeout) extends SocketClient {
            public function __construct(UriInterface $uri, ?Context $context, private readonly CapturingStreamFactory $factory, private readonly float $connectTimeout)
            {
                parent::__construct($uri, $context);
            }

            public function connect(): DeadlineSocketStream
            {
                $this->setTimeout($this->connectTimeout);

                return $this->factory->capture(parent::connect());
            }
        };
    }

    /**
     * The socket resource of the connection, or null when none was made.
     *
     * @return resource|null
     */
    public function resource()
    {
        $resource = $this->captured?->getResource();

        return \is_resource($resource) ? $resource : null;
    }

    /**
     * @param ?float $deadline microtime(true) after which reads fail; null for none
     */
    public function setDeadline(?float $deadline): void
    {
        $this->deadline = $deadline;
        $this->captured?->setDeadline($deadline);
    }

    public function forget(): void
    {
        $this->captured = null;
    }

    /**
     * @internal called by the socket client this factory creates
     */
    public function capture(SocketStream $stream): DeadlineSocketStream
    {
        $resource = $stream->getResource();
        if (!\is_resource($resource)) {
            throw new \LogicException('A connected socket stream has no resource.');
        }
        $bounded = new DeadlineSocketStream($resource);
        $bounded->setDeadline($this->deadline);

        return $this->captured = $bounded;
    }
}
