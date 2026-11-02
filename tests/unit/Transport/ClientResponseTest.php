<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Auth\BearerCredentials;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\TokenProvider;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\MutationOutcomeUnknownException;
use WhiteMarket\Sdk\Errors\MutationRejection;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Export\ExportTransport;
use WhiteMarket\Sdk\GraphQL\GraphQLExecutor;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\HostileResponse;
use WhiteMarket\Sdk\Tests\Support\RecordingSleeper;
use WhiteMarket\Sdk\Tests\Support\TestMoney;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Transport\ClientResponse;
use WhiteMarket\Sdk\Transport\ClientStream;
use WhiteMarket\Sdk\Wallet\WalletApi;

final class CountingTokenProvider implements TokenProvider
{
    private int $refreshes = 0;

    public function refreshes(): int
    {
        return $this->refreshes;
    }

    public function accessToken(): Secret
    {
        return new Secret('synthetic-jwt-' . $this->refreshes);
    }

    public function refresh(): Secret
    {
        ++$this->refreshes;

        return $this->accessToken();
    }
}

/**
 * CLAUDE.md rule 2: a method of the response or of its body stream is client code too. Whatever it throws (an
 * `AuthenticationException` built from the client's own 401 above all) must not look like an answer of the API: a
 * mutation is neither replayed nor "rejected".
 */
#[CoversClass(ClientResponse::class)]
#[CoversClass(ClientStream::class)]
final class ClientResponseTest extends TestCase
{
    /**
     * @return iterable<string, array{string, \Throwable}>
     */
    public static function clientFailures(): iterable
    {
        foreach (HostileResponse::POINTS as $point) {
            yield $point . ' throws AuthenticationException' => [$point, new AuthenticationException(401, 'client text synthetic')];
            yield $point . ' throws RuntimeException' => [$point, new \RuntimeException('client text synthetic')];
            yield $point . ' throws Error' => [$point, new \Error('client text synthetic')];
        }
    }

    #[Test]
    #[DataProvider('clientFailures')]
    public function aMutationWhoseResponseFailsIsSentOnceAndIsATransportFailure(string $point, \Throwable $failure): void
    {
        $http = new FakeHttpClient(HostileResponse::at($point, $failure), HostileResponse::at($point, $failure));
        $provider = $this->refreshingProvider();
        $executor = new GraphQLExecutor(TestTransport::over($http), new BearerCredentials($provider));

        try {
            $executor->execute('mutation M { x }', 'M', OperationType::Mutation, []);
            self::fail('Expected a TransportException');
        } catch (TransportException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringContainsString($failure::class, $e->getMessage());
            self::assertStringNotContainsString('client text', $e->getMessage());
            self::assertFalse((new MutationRejection())->isRejection($e, 'x'));
        }

        self::assertCount(1, $http->requests(), 'no 401 was answered, so nothing is replayed');
        self::assertSame(0, $provider->refreshes());
    }

    #[Test]
    #[DataProvider('clientFailures')]
    public function aWalletMutationWhoseResponseFailsIsOutcomeUnknown(string $point, \Throwable $failure): void
    {
        $http = new FakeHttpClient(HostileResponse::at($point, $failure), HostileResponse::at($point, $failure));

        try {
            (new WalletApi(TestTransport::generatedClient($http), new ModelMapper()))->transfer('someone', TestMoney::usd('5.25'));
            self::fail('Expected a MutationOutcomeUnknownException');
        } catch (MutationOutcomeUnknownException $e) {
            self::assertInstanceOf(TransportException::class, $e->getPrevious());
        }

        self::assertCount(1, $http->requests());
    }

    #[Test]
    #[DataProvider('clientFailures')]
    public function anExportGetWhoseResponseFailsIsATransportFailureAndNotRetried(string $point, \Throwable $failure): void
    {
        $http = new FakeHttpClient(HostileResponse::at($point, $failure), HostileResponse::at($point, $failure));

        try {
            $body = $this->export($http)->get('https://export.white.market/v1/prices/730.json');
            // A failing body stream is reached when the body is read, not when the response is accepted.
            $body->read(1000);
            self::fail('Expected a TransportException');
        } catch (TransportException $e) {
            self::assertNull($e->getPrevious());
        }

        self::assertCount(1, $http->requests());
    }

    /**
     * @return iterable<string, array{ResponseInterface}> a final response whose body is released unread
     */
    public static function releasedUnread(): iterable
    {
        yield 'retried 503' => [HostileResponse::at('close', new AuthenticationException(401, ''), 503)];
        yield 'redirect' => [HostileResponse::at('close', new AuthenticationException(401, ''), 302, '', ['Location' => '/v1/other.json'])];
    }

    #[Test]
    #[DataProvider('releasedUnread')]
    public function aBodyThatCannotBeReleasedFailsTheExportGet(ResponseInterface $response): void
    {
        $http = new FakeHttpClient($response, $response);

        $this->expectException(TransportException::class);
        try {
            $this->export($http)->get('https://export.white.market/v1/prices/730.json');
        } finally {
            self::assertCount(1, $http->requests());
        }
    }

    #[Test]
    public function aQueryBodyThatCannotBeReleasedBeforeARetryIsATransportFailure(): void
    {
        $http = new FakeHttpClient(HostileResponse::at('close', new AuthenticationException(401, ''), 503), FakeHttpClient::status(200, '{"data":{}}'));
        $executor = new GraphQLExecutor(TestTransport::over($http), new BearerCredentials($this->refreshingProvider()));

        $this->expectException(TransportException::class);
        try {
            $executor->execute('query Q { x }', 'Q', OperationType::Query, []);
        } finally {
            self::assertCount(1, $http->requests());
        }
    }

    private function refreshingProvider(): CountingTokenProvider
    {
        return new CountingTokenProvider();
    }

    private function export(FakeHttpClient $http): ExportTransport
    {
        return new ExportTransport($http, new HttpFactory(), new ExportOptions(), sleeper: new RecordingSleeper());
    }
}
