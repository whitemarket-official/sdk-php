<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use GuzzleHttp\Client;
use GuzzleHttp\RequestOptions;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Tests\Support\LoopbackServer;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;
use WhiteMarket\Sdk\Transport\TransportOptions;

#[CoversClass(GuzzleClientFactory::class)]
final class GuzzleClientFactoryTest extends TestCase
{
    private const CREDENTIAL_HEADERS = ['Authorization' => 'Bearer synthetic-jwt', 'X-partner-token' => 'synthetic-partner-token'];

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

    /** @return iterable<string, array{\Closure(): ClientInterface}> */
    public static function defaultClients(): iterable
    {
        yield 'query client' => [static fn(): ClientInterface => GuzzleClientFactory::create()];
        yield 'mutation client' => [static fn(): ClientInterface => GuzzleClientFactory::createForMutations()];
        yield 'live (SSE) client' => [static fn(): ClientInterface => GuzzleClientFactory::createForLive()];
        yield 'export client' => [static fn(): ClientInterface => GuzzleClientFactory::createForExports()];
    }

    /** @param \Closure(): ClientInterface $client */
    #[Test]
    #[DataProvider('defaultClients')]
    public function sendsNothingToTheHostARedirectPointsAt(\Closure $client): void
    {
        $received = LoopbackServer::bodiesReceived('redirect-elsewhere', $client(), ['only'], headers: self::CREDENTIAL_HEADERS);

        self::assertSame("only\n", $received);
    }

    /**
     * Control for {@see sendsNothingToTheHostARedirectPointsAt()}: a client that follows the redirect does reach the
     * other host, and Guzzle's own cross-host stripping drops `Authorization` but keeps `X-partner-token`.
     */
    #[Test]
    public function aClientThatFollowsRedirectsCarriesThePartnerTokenToTheOtherHost(): void
    {
        $following = new class implements ClientInterface {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return (new Client())->send($request, [RequestOptions::ALLOW_REDIRECTS => true, RequestOptions::HTTP_ERRORS => false]);
            }
        };

        $received = LoopbackServer::bodiesReceived('redirect-elsewhere', $following, ['only'], headers: self::CREDENTIAL_HEADERS);

        self::assertSame("only\nredirected authorization=no x-partner-token=yes only\n", $received);
    }

    #[Test]
    public function buildsRequestsWithTheMessageFactory(): void
    {
        $factory = GuzzleClientFactory::messageFactory();

        $request = $factory->createRequest('POST', 'https://api.example.test/graphql/partner')->withBody($factory->createStream('{}'));

        self::assertSame('POST https://api.example.test/graphql/partner {}', $request->getMethod() . ' ' . $request->getUri() . ' ' . $request->getBody());
    }
}
