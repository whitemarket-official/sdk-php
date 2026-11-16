<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use WhiteMarket\Sdk\Live\Live;
use WhiteMarket\Sdk\Live\LiveFactory;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveTransport;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\ScriptedTokens;
use WhiteMarket\Sdk\Tests\Support\ThrowingRequestFactory;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(LiveFactory::class)]
final class LiveFactoryTest extends TestCase
{
    /**
     * @return iterable<string, array{LiveTransport, string, string}>
     */
    public static function transports(): iterable
    {
        yield 'SSE builds the connect URL' => [LiveTransport::Sse, 'ssePath', 'https://api.example.test/sse?cf_connect=%7B%7D'];
        yield 'WebSocket builds the wss URL' => [LiveTransport::WebSocket, 'wsPath', 'wss://api.example.test/ws'];
    }

    #[Test]
    #[DataProvider('transports')]
    public function refusesAnEndpointTheRequestFactoryCannotBuildWithoutRevealingItsMessage(LiveTransport $transport, string $option, string $url): void
    {
        $factory = ThrowingRequestFactory::refusingEvery('Unable to parse URI: ' . $url . ' synthetic-token-77aa');
        $options = new LiveOptions(transport: $transport, wsPath: '/ws', ssePath: '/sse');

        $e = TraceArguments::caught(fn(): Live => $this->factory($factory)->create($options));

        TraceArguments::assertOpaque($e, \InvalidArgumentException::class, $option . ' is not a valid URL.', 'synthetic-token-77aa');
        self::assertSame([['GET', $url]], $factory->calls(), 'a placeholder connect command, never a token');
    }

    #[Test]
    public function createsALiveWhenTheFactoryCanBuildTheUrl(): void
    {
        $factory = ThrowingRequestFactory::recordingOnly();

        $this->factory($factory)->create(new LiveOptions(transport: LiveTransport::Sse, ssePath: '/sse'));

        self::assertSame([['GET', 'https://api.example.test/sse?cf_connect=%7B%7D']], $factory->calls());
    }

    private function factory(ThrowingRequestFactory $requestFactory): LiveFactory
    {
        return new LiveFactory(new ScriptedTokens(), 'https://api.example.test/', new FakeHttpClient(), $requestFactory, new NullLogger());
    }
}
