<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Log\LoggerInterface;
use WhiteMarket\Sdk\Auth\TokenProvider;
use WhiteMarket\Sdk\Live\Sse\SseConnection;
use WhiteMarket\Sdk\Live\WebSocket\WebSocketConnection;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;
use WhiteMarket\Sdk\Transport\OptionRules;
use WhiteMarket\Sdk\Transport\RandomSource;
use WhiteMarket\Sdk\Transport\Sleeper;
use WhiteMarket\Sdk\Transport\SystemClock;
use WhiteMarket\Sdk\Transport\SystemRandomSource;
use WhiteMarket\Sdk\Transport\SystemSleeper;

/**
 * Builds a {@see Live} per {@see \WhiteMarket\Sdk\WhiteMarketClient::live()} call from what the client holds. An injected
 * PSR-18 client is used for SSE as is (its timeouts decide); otherwise the live Guzzle client is built on first use.
 *
 * @internal
 */
final readonly class LiveFactory
{
    public function __construct(
        private TokenProvider $tokens,
        private string $baseUrl,
        private ?ClientInterface $httpClient,
        private RequestFactoryInterface $requestFactory,
        private LoggerInterface $logger,
        private Clock $clock = new SystemClock(),
        private Sleeper $sleeper = new SystemSleeper(),
        private RandomSource $random = new SystemRandomSource(),
    ) {}

    /**
     * @throws \InvalidArgumentException the request factory cannot build the URL of the chosen transport
     */
    public function create(LiveOptions $options): Live
    {
        // The WS URL is probed with the PSR-17 factory as a URL check only: the WebSocket library parses it itself (its own
        // refusals, a bad port and an empty host, are covered by OptionRules). The SSE URL gets a placeholder connect
        // command: the real one carries the token and is built at connect time.
        [$option, $url] = match ($options->transport) {
            LiveTransport::WebSocket => ['wsPath', WebSocketConnection::url($this->baseUrl, $options->wsPath)],
            LiveTransport::Sse => ['ssePath', SseConnection::url($this->baseUrl, $options->ssePath, '{}')],
        };
        OptionRules::assertBuildable($this->requestFactory, $option, $url);
        $connection = match ($options->transport) {
            LiveTransport::WebSocket => fn(): Connection => new WebSocketConnection($this->baseUrl, $options),
            LiveTransport::Sse => fn(): Connection => new SseConnection(
                $this->httpClient ?? GuzzleClientFactory::createForLive($options),
                $this->requestFactory,
                $this->baseUrl,
                $options,
                $this->clock,
            ),
        };

        $handlers = new LiveHandlers();

        return new Live(
            new LiveSession($this->tokens, $connection, $options, $this->logger, $this->clock, $this->sleeper, $handlers, $this->random),
            $handlers,
        );
    }
}
