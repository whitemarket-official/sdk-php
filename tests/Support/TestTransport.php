<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use GuzzleHttp\Psr7\HttpFactory;
use WhiteMarket\Sdk\Auth\BearerCredentials;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\StaticAccessToken;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\GraphQL\GraphQLExecutor;
use WhiteMarket\Sdk\Transport\Clock;
use WhiteMarket\Sdk\Transport\HttpClients;
use WhiteMarket\Sdk\Transport\HttpTransport;
use WhiteMarket\Sdk\Transport\RetryPolicy;

/**
 * An {@see HttpTransport} over a fake HTTP client with deterministic time, sleep and jitter.
 */
final class TestTransport
{
    private function __construct() {}

    public static function over(FakeHttpClient $http, Clock $clock = new FrozenClock(), RetryPolicy $retryPolicy = new RetryPolicy()): HttpTransport
    {
        $factory = new HttpFactory();

        return new HttpTransport(new HttpClients($http, $http), $factory, $factory, retryPolicy: $retryPolicy, clock: $clock, sleeper: new RecordingSleeper(), random: new FixedRandomSource(0.5));
    }

    public static function generatedClient(FakeHttpClient $http, RetryPolicy $retry = new RetryPolicy()): GeneratedClient
    {
        return new GeneratedClient(new GraphQLExecutor(self::over($http, retryPolicy: $retry), new BearerCredentials(new StaticAccessToken(new Secret('synthetic-access-token')))));
    }
}
