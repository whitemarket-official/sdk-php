<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live\Sse;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\Sse\SseConnection;
use WhiteMarket\Sdk\Tests\Support\FixtureServer;
use WhiteMarket\Sdk\Tests\Support\SyntheticJwt;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;

/**
 * The SSE connection with the real default live client over a real socket (`tests/fixtures/servers/sse.php`): the
 * check of plan assumption A6, an expired read slice does not end the stream and the connection's deadline works.
 */
#[CoversClass(SseConnection::class)]
final class SseConnectionSocketTest extends TestCase
{
    private ?FixtureServer $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    /**
     * @param-out int $slices how many read slices expired without data
     */
    private function connect(string $mode, ?int &$slices = null): SseConnection
    {
        $this->server = FixtureServer::start('sse', [$mode]);
        $slices = 0;

        $client = new class (GuzzleClientFactory::createForLive(new LiveOptions()), $slices) implements ClientInterface {
            public function __construct(private readonly ClientInterface $inner, private int &$slices) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $response = $this->inner->sendRequest($request);

                return $response->withBody(new class ($response->getBody(), $this->slices) implements StreamInterface {
                    use StreamDecoratorTrait;

                    public function __construct(private StreamInterface $stream, private int &$slices) {}

                    public function read(int $length): string
                    {
                        try {
                            return $this->stream->read($length);
                        } catch (\Throwable $e) {
                            ++$this->slices;

                            throw $e;
                        }
                    }
                });
            }
        };

        return new SseConnection($client, GuzzleClientFactory::messageFactory(), $this->server->baseUrl(), new LiveOptions());
    }

    private function open(SseConnection $connection): void
    {
        $connection->open(new Secret(SyntheticJwt::withClaims(['sub' => 'synthetic-subject'])), ['synthetic-channel'], 10);
    }

    #[Test]
    public function aSliceThatExpiresWithoutDataDoesNotEndTheStream(): void
    {
        $connection = $this->connect('delayed', $slices);
        $this->open($connection);
        $this->server?->release();

        $start = microtime(true);
        $event = $connection->read(10);
        $elapsed = microtime(true) - $start;

        self::assertSame('{"channel":"synthetic-channel","pub":{"data":{"message":"{}"}}}', $event);
        self::assertGreaterThanOrEqual(2, $slices, 'the event came after the slices expired');
        self::assertLessThan(4.5, $elapsed);
        $connection->close();
    }

    #[Test]
    public function aReadReturnsNullAtItsDeadlineWithinOneSlice(): void
    {
        $connection = $this->connect('delayed');
        $this->open($connection);
        $this->server?->release();

        $start = microtime(true);
        $event = $connection->read(1.5);
        $elapsed = microtime(true) - $start;

        self::assertNull($event);
        self::assertGreaterThanOrEqual(1.5, $elapsed);
        self::assertLessThan(2.9, $elapsed);
        // The connection is still usable: the event arrives later.
        self::assertNotNull($connection->read(10));
        $connection->close();
    }

    #[Test]
    public function anEventCutByTheEndOfTheStreamIsDroppedAndTheEndFails(): void
    {
        $connection = $this->connect('mid-event');
        $this->open($connection);

        $this->expectException(TransportException::class);
        $connection->read(10);
    }

    #[Test]
    public function aBurstIsDeliveredEventByEventThenTheEndFails(): void
    {
        $connection = $this->connect('burst');
        $this->open($connection);

        for ($i = 0; $i < 3; ++$i) {
            self::assertNotNull($connection->read(10));
        }

        $this->expectException(TransportException::class);
        $connection->read(10);
    }

    /**
     * The open body stream of the default client keeps the request URL, with the token in its query, in its metadata.
     */
    #[Test]
    public function aDumpOfTheConnectionShowsNoTokenWhileOpenOrAfterItBroke(): void
    {
        $connection = $this->connect('mid-event');
        $this->open($connection);
        $dumps = [print_r($connection, true), $this->varDump($connection)];
        try {
            $connection->read(10);
            self::fail('expected the stream end to fail');
        } catch (TransportException) {
            $dumps[] = print_r($connection, true);
            $dumps[] = $this->varDump($connection);
        }

        foreach ($dumps as $dump) {
            self::assertStringNotContainsString('cf_connect', $dump);
            self::assertStringNotContainsString('eyJ', $dump);
        }
    }

    private function varDump(mixed $value): string
    {
        ob_start();
        var_dump($value);

        return (string) ob_get_clean();
    }

    #[Test]
    public function aChunkedBodyDeliversTheEventWithAtMostOneSliceOfExtraLatency(): void
    {
        $connection = $this->connect('chunked');
        $this->open($connection);

        $start = microtime(true);
        $event = $connection->read(10);

        self::assertNotNull($event);
        self::assertLessThan(5.0, microtime(true) - $start);
        $connection->close();
    }
}
