<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Tests\Support\LoopbackServer;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;
use WhiteMarket\Sdk\Transport\TransportOptions;

#[CoversClass(GuzzleClientFactory::class)]
final class GuzzleClientFactoryTest extends TestCase
{
    #[Test]
    public function configuresTimeoutsTlsRedirectsAndDecompression(): void
    {
        $config = GuzzleClientFactory::config(new TransportOptions(connectTimeout: 2.5, requestTimeout: 7.5));
        ksort($config);

        self::assertSame(
            ['allow_redirects' => false, 'connect_timeout' => 2.5, 'decode_content' => false, 'http_errors' => false, 'timeout' => 7.5, 'verify' => true],
            $config,
        );
    }

    #[Test]
    public function configuresExportDownloadsWithAnIdleTimeoutAndNoTotalTimeout(): void
    {
        $config = GuzzleClientFactory::exportConfig(new ExportOptions(connectTimeout: 2.5, idleTimeout: 45.0));
        ksort($config);

        self::assertSame(
            ['allow_redirects' => false, 'connect_timeout' => 2.5, 'decode_content' => false, 'http_errors' => false, 'read_timeout' => 45.0, 'stream' => true, 'verify' => true],
            $config,
        );
        self::assertArrayNotHasKey('timeout', $config);
    }

    #[Test]
    public function configuresLiveStreamsWithAReadSliceAndNoTotalTimeout(): void
    {
        $config = GuzzleClientFactory::liveConfig(new LiveOptions(connectTimeout: 2.5, readSlice: 3));
        ksort($config);

        self::assertSame(
            ['allow_redirects' => false, 'connect_timeout' => 2.5, 'decode_content' => false, 'http_errors' => false, 'read_timeout' => 3, 'stream' => true, 'verify' => true],
            $config,
        );
        self::assertArrayNotHasKey('timeout', $config);
    }

    #[Test]
    public function buildsAGuzzleClientForLive(): void
    {
        self::assertInstanceOf(Client::class, GuzzleClientFactory::createForLive());
    }

    #[Test]
    public function buildsAGuzzleClientForExports(): void
    {
        self::assertInstanceOf(Client::class, GuzzleClientFactory::createForExports());
    }

    #[Test]
    public function buildsAGuzzleClient(): void
    {
        self::assertInstanceOf(Client::class, GuzzleClientFactory::create());
    }

    /** @return iterable<string, array{0: string, 1: list<string>, 2?: bool}> */
    public static function connectionsClosedWithoutAResponse(): iterable
    {
        yield from LoopbackServer::connectionsClosedWithoutAResponse();
    }

    /** @param list<string> $bodies */
    #[Test]
    #[DataProvider('connectionsClosedWithoutAResponse')]
    public function sendsEachMutationOnceWhenTheServerClosesTheConnectionWithoutAResponse(string $mode, array $bodies, bool $bodyReadBeforeSending = false): void
    {
        $received = LoopbackServer::bodiesReceived($mode, GuzzleClientFactory::createForMutations(), $bodies, $bodyReadBeforeSending);

        self::assertSame(implode("\n", $bodies) . "\n", $received);
    }

    #[Test]
    public function buildsRequestsWithTheMessageFactory(): void
    {
        $factory = GuzzleClientFactory::messageFactory();

        $request = $factory->createRequest('POST', 'https://api.example.test/graphql/partner')->withBody($factory->createStream('{}'));

        self::assertSame('POST https://api.example.test/graphql/partner {}', $request->getMethod() . ' ' . $request->getUri() . ' ' . $request->getBody());
    }
}
