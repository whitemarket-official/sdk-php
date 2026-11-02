<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use WhiteMarket\Sdk\Export\ExportClient;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Export\ExportTransport;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\Transport\Sleeper;

/**
 * An {@see ExportTransport} and an {@see ExportClient} over a fake HTTP client with deterministic time, sleep and
 * jitter, and the synthetic export files of `tests/fixtures/exports`.
 */
final class TestExport
{
    private function __construct() {}

    public static function transport(
        FakeHttpClient $http,
        ExportOptions $options = new ExportOptions(),
        ?CacheInterface $cache = null,
        RetryPolicy $retryPolicy = new RetryPolicy(),
        ?Sleeper $sleeper = null,
        ?Clock $clock = null,
        LoggerInterface $logger = new NullLogger(),
    ): ExportTransport {
        return new ExportTransport(
            $http,
            new HttpFactory(),
            $options,
            $cache,
            $retryPolicy,
            $logger,
            $clock ?? new FrozenClock(),
            $sleeper ?? new RecordingSleeper(),
            new FixedRandomSource(0.5),
        );
    }

    public static function client(
        FakeHttpClient $http,
        ExportOptions $options = new ExportOptions(),
        ?CacheInterface $cache = null,
        ?Clock $clock = null,
        RetryPolicy $retryPolicy = new RetryPolicy(),
    ): ExportClient {
        return new ExportClient(
            self::transport($http, $options, $cache, $retryPolicy, clock: $clock),
            new HttpFactory(),
            $options,
            $clock ?? new FrozenClock(),
        );
    }

    /**
     * @param 'prices.json'|'prices.meta.json' $name
     */
    public static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/exports/' . $name);
    }
}
