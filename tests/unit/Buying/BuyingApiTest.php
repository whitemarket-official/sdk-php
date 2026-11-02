<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Buying;

use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Auth\BearerCredentials;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\StaticAccessToken;
use WhiteMarket\Sdk\Buying\BuyingApi;
use WhiteMarket\Sdk\Buying\BuyOptions;
use WhiteMarket\Sdk\Buying\BuyReconciliation;
use WhiteMarket\Sdk\Buying\BuyResult;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\BadRequestException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GeneralGraphQLException;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\PurchaseOutcomeUnknownException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\ServiceUnavailableException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketBuy;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\DealItem;
use WhiteMarket\Sdk\GraphQL\GraphQLExecutor;
use WhiteMarket\Sdk\GraphQL\OperationExecutor;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Models\WireEncoder;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\RecordingSleeper;
use WhiteMarket\Sdk\Tests\Support\SyntheticDeal;
use WhiteMarket\Sdk\Tests\Support\SyntheticJwt;
use WhiteMarket\Sdk\Tests\Support\TestMoney;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Transport\HttpClients;
use WhiteMarket\Sdk\Transport\HttpTransport;
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\WhiteMarketClient;

#[CoversClass(BuyingApi::class)]
#[CoversClass(BuyResult::class)]
#[CoversClass(WireEncoder::class)]
final class BuyingApiTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    private const NOT_FOUND = ['errors' => [['message' => 'Deal not found', 'extensions' => ['category' => 'market.deal.not_found']]]];

    private const BUY_NS = MarketBuy::class;

    private const DELAY = 12.0;

    private const INTERVAL = 2.0;

    /**
     * @return array{BuyingApi, FakeHttpClient, RecordingSleeper}
     */
    private function api(
        FakeHttpClient $http,
        ?BuyReconciliation $reconciliation = null,
        RetryPolicy $retry = new RetryPolicy(maxAttempts: 1),
    ): array {
        $generated = TestTransport::generatedClient($http, $retry);
        $sleeper = new RecordingSleeper();
        $api = new BuyingApi(
            $generated,
            new ModelMapper(),
            $reconciliation ?? new BuyReconciliation(delay: self::DELAY, attempts: 3, interval: self::INTERVAL),
            $sleeper,
        );

        return [$api, $http, $sleeper];
    }

    // ---- success -----------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{string, array<string, mixed>}> the call ({@see buy()}), expected variables without
     *                                                               customId
     */
    public static function buyCalls(): iterable
    {
        yield 'buyProduct' => ['product', ['id' => '7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13']];
        yield 'buyCheapest with every filter' => [
            'cheapest-all',
            [
                'appIdNameHashInput' => ['app' => 'CSGO', 'nameHash' => 'Doppler Karambit (Factory New)', 'phase' => 'BLACK_PEARL'],
                'deliveryPromise' => 'HOUR1',
                'maxPriceInput' => ['currency' => 'USD', 'value' => '12.50'],
            ],
        ];
        yield 'buyCheapest with only the required arguments' => ['cheapest-minimal', ['appIdNameHashInput' => ['app' => 'TF2', 'nameHash' => 'Mann Co. Supply Crate Key']]];
        yield 'buyByAssetId' => ['asset', ['appIdAssetIdInput' => ['app' => 'DOTA2', 'assetId' => '40000000000000000002']]];
    }

    /**
     * @throws WhiteMarketException
     */
    private function buy(BuyingApi $api, string $call, ?BuyOptions $options): BuyResult
    {
        return match ($call) {
            'product' => $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: $options),
            'cheapest-all' => $api->buyCheapest(App::Csgo, 'Doppler Karambit (Factory New)', TestMoney::usd('12.50'), Phase::BlackPearl, DeliveryPromise::Hour1, $options),
            'cheapest-minimal' => $api->buyCheapest(App::Tf2, 'Mann Co. Supply Crate Key', options: $options),
            'asset' => $api->buyByAssetId(App::Dota2, '40000000000000000002', options: $options),
            default => throw new \LogicException('Unknown call ' . $call),
        };
    }

    /**
     * @param array<string, mixed> $target
     */
    #[Test]
    #[DataProvider('buyCalls')]
    public function sendsOneMarketBuyWithTheGivenCustomIdAndMapsTheDeal(string $call, array $target): void
    {
        [$api, $http, $sleeper] = $this->api(new FakeHttpClient($this->bought('order-42')));

        $result = $this->buy($api, $call, new BuyOptions(customId: 'order-42'));

        $expected = ['customId' => 'order-42'] + $target;
        ksort($expected);
        self::assertSame([['MarketBuy', $expected]], $http->operations());
        self::assertSame(SyntheticDeal::DEAL_ID, $result->deal->id);
        self::assertSame(DealStatus::Offered, $result->deal->status);
        self::assertSame('25.000 USD', (string) $result->deal->price);
        self::assertSame('order-42', $result->customId);
        self::assertFalse($result->reconciled);
        self::assertSame([], $sleeper->delays());
    }

    /**
     */
    #[Test]
    #[DataProvider('buyCalls')]
    public function neverSendsADeliveryTypeOrALinkUnlessGiven(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->bought('order-42')));

        $this->buy($api, $call, null);

        $variables = $this->variablesOf($http, 0);
        self::assertArrayNotHasKey('deliveryType', $variables);
        self::assertArrayNotHasKey('link', $variables);
    }

    /**
     */
    #[Test]
    #[DataProvider('buyCalls')]
    public function sendsTheTradeUrlOfAThirdPartyAsALink(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->bought('order-42')));

        $this->buy($api, $call, new BuyOptions(customId: 'order-42', tradeUrl: 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic'));

        self::assertSame(['value' => 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic'], $this->variablesOf($http, 0)['link'] ?? null);
    }

    /**
     * @return iterable<string, array{string, int}> the call ({@see buy()}), the status of the failed `market_buy`
     */
    public static function failedBuysWithATradeUrl(): iterable
    {
        foreach (self::buyCalls() as $name => [$call]) {
            yield $name . ', rejected (400)' => [$call, 400];
            yield $name . ', ambiguous (500, reconciled)' => [$call, 500];
        }
    }

    /**
     * `BuyOptions` masks itself for `print_r`, but `var_export` of a trace frame ignores `__debugInfo`, and the closure
     * that sends `market_buy` captures the link as a plain string.
     */
    #[Test]
    #[DataProvider('failedBuysWithATradeUrl')]
    public function aFailedBuyKeepsTheTradeUrlOutOfEveryTraceFrame(string $call, int $status): void
    {
        $responses = [FakeHttpClient::status($status)];
        for ($i = 0; $i < 6; ++$i) {
            $responses[] = FakeHttpClient::status(404);
        }
        [$api] = $this->api(new FakeHttpClient(...$responses), new BuyReconciliation(delay: self::DELAY, attempts: 1, interval: self::INTERVAL));

        $e = TraceArguments::caught(function () use ($api, $call): void {
            $this->buy($api, $call, new BuyOptions(customId: 'order-42', tradeUrl: 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=syntheticTradeToken'));
        });

        $failures = [];
        for ($failure = $e; $failure instanceof \Throwable; $failure = $failure->getPrevious()) {
            $failures[] = $failure;
        }
        if ($e instanceof PurchaseOutcomeUnknownException && $e->lastLookupError instanceof \Throwable) {
            $failures[] = $e->lastLookupError;
        }
        foreach ($failures as $failure) {
            // A `Throwable` argument is exported whole: its trace reaches the caller's frames, which hold the link.
            self::assertStringNotContainsString('syntheticTradeToken', TraceArguments::exportedSdkFrames($failure), $failure::class);
        }
    }

    /**
     */
    #[Test]
    #[DataProvider('buyCalls')]
    public function generatesAUuidV4CustomIdAndUsesItForTheCallAndTheResult(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->bought('generated'), $this->bought('generated')));

        $first = $this->buy($api, $call, null);
        $second = $this->buy($api, $call, new BuyOptions());

        $sent = [$this->variablesOf($http, 0)['customId'] ?? null, $this->variablesOf($http, 1)['customId'] ?? null];
        self::assertMatchesRegularExpression(self::UUID_V4, $first->customId);
        self::assertMatchesRegularExpression(self::UUID_V4, $second->customId);
        self::assertNotSame($first->customId, $second->customId);
        self::assertSame([$first->customId, $second->customId], $sent);
    }

    // ---- rejections: exactly one request, nothing else ---------------------------------------------------------

    /**
     * @return iterable<string, array{ResponseInterface, class-string<WhiteMarketException>}>
     */
    public static function rejections(): iterable
    {
        yield 'GraphQL error' => [FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Not enough funds', 'extensions' => ['category' => 'market.buy.insufficient_funds']]]]), GeneralGraphQLException::class];
        yield 'GraphQL error without a category' => [FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Rejected']]]), GeneralGraphQLException::class];
        yield 'GraphQL not found' => [FakeHttpClient::json(['data' => null, 'errors' => [['message' => 'Gone', 'extensions' => ['category' => 'market.product.not_found']]]]), NotFoundException::class];
        yield 'HTTP 400' => [FakeHttpClient::status(400, 'bad'), BadRequestException::class];
        yield 'HTTP 401 with a token that cannot be refreshed' => [FakeHttpClient::status(401), AuthenticationException::class];
        yield 'HTTP 429' => [FakeHttpClient::status(429), RateLimitException::class];
        yield 'HTTP 429 with Retry-After' => [FakeHttpClient::status(429, '', ['Retry-After' => '30']), RateLimitException::class];
    }

    /**
     * @param class-string<WhiteMarketException> $expected
     */
    #[Test]
    #[DataProvider('rejections')]
    public function rethrowsARejectionAfterExactlyOneRequest(ResponseInterface $response, string $expected): void
    {
        [$api, $http, $sleeper] = $this->api(new FakeHttpClient($response));

        try {
            $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));
            self::fail('The rejection must reach the caller.');
        } catch (WhiteMarketException $e) {
            self::assertSame($e::class, $expected);
        }

        self::assertSame(['MarketBuy'], array_column($http->operations(), 0), 'no lookup follows a rejection');
        self::assertSame([], $sleeper->delays());
    }

    /**
     * WHITEMARKET_API.md 2: a response may carry partial `data` next to `errors`; an error below `market_buy`, or a
     * deal in `data`, means the mutation ran.
     *
     * @return iterable<string, array{array<string, mixed>, bool}> response payload, whether it is a clean rejection
     */
    public static function graphQlErrorsAfterTheMutation(): iterable
    {
        $deal = SyntheticDeal::inProgress(self::BUY_NS, ['customId' => 'order-42']);
        $error = static fn(array $path): array => ['message' => 'Failure', 'path' => $path, 'extensions' => ['category' => 'internal']];

        yield 'nested-path error with the created deal in data -> reconcile' => [['data' => ['market_buy' => $deal], 'errors' => [$error(['market_buy', 'item', 'description'])]], false];
        yield 'nested-path error with market_buy null -> reconcile' => [['data' => ['market_buy' => null], 'errors' => [$error(['market_buy', 'product', 'price'])]], false];
        yield 'root-path error with market_buy null -> rejection' => [['data' => ['market_buy' => null], 'errors' => [$error(['market_buy'])]], true];
        yield 'error without a path and data null -> rejection' => [['data' => null, 'errors' => [['message' => 'Rejected']]], true];
        yield 'root-path error but market_buy in data -> reconcile' => [['data' => ['market_buy' => $deal], 'errors' => [$error(['market_buy'])]], false];
        yield 'root-path error with an empty data object -> reconcile (ambiguous)' => [['data' => [], 'errors' => [$error(['market_buy'])]], false];
        yield 'root-path error with data without market_buy -> reconcile (ambiguous)' => [['data' => ['other' => null], 'errors' => [$error(['market_buy'])]], false];
        yield 'error without a path and data without market_buy -> reconcile (ambiguous)' => [['data' => ['other' => null], 'errors' => [['message' => 'Rejected']]], false];
        yield 'root-path error with a non-null scalar market_buy -> reconcile' => [['data' => ['market_buy' => 'x'], 'errors' => [$error(['market_buy'])]], false];
        yield 'deal inside a data list -> reconcile' => [['data' => [['market_buy' => $deal]], 'errors' => [['message' => 'Failure']]], false];
        yield 'one root and one nested error -> reconcile' => [['data' => ['market_buy' => null], 'errors' => [$error(['market_buy']), $error(['market_buy', 'item'])]], false];
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataProvider('graphQlErrorsAfterTheMutation')]
    public function aGraphQlErrorIsARejectionOnlyWhenTheMutationCannotHaveRun(array $payload, bool $rejection): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json($payload), $this->found('DealItem', 'order-42')));

        if ($rejection) {
            try {
                $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));
                self::fail('The rejection must reach the caller.');
            } catch (GraphQLException) {
                self::assertSame(['MarketBuy'], array_column($http->operations(), 0), 'no lookup follows a rejection');
            }

            return;
        }

        $result = $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));

        self::assertTrue($result->reconciled);
        self::assertSame(['MarketBuy', 'DealItem'], array_column($http->operations(), 0));
    }

    /**
     * A malformed `path` is a decode failure of the whole response, so it can never read as a root error (`[]`).
     *
     * @return iterable<string, array{mixed}>
     */
    public static function malformedErrorPaths(): iterable
    {
        yield 'null segment' => [['market_buy', 'item', null]];
        yield 'float segment' => [['market_buy', 'item', 'stickers', 1.5]];
        yield 'dotted string' => ['market_buy.item.description'];
        yield 'object' => [['a' => 'market_buy']];
    }

    #[Test]
    #[DataProvider('malformedErrorPaths')]
    public function aMalformedErrorPathIsReconciledNotTakenForARejection(mixed $path): void
    {
        $payload = ['data' => ['market_buy' => null], 'errors' => [['message' => 'Failure', 'path' => $path, 'extensions' => ['category' => 'internal']]]];
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json($payload), $this->found('DealItem', 'order-42')));

        $result = $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));

        self::assertTrue($result->reconciled);
        self::assertSame(['MarketBuy', 'DealItem'], array_column($http->operations(), 0));
    }

    #[Test]
    public function aNotFoundErrorBelowMarketBuyIsReconciledToo(): void
    {
        $payload = ['data' => ['market_buy' => null], 'errors' => [['message' => 'Gone', 'path' => ['market_buy', 'item'], 'extensions' => ['category' => 'market.product.not_found']]]];
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::json($payload), $this->found('DealItem', 'order-42')));

        $result = $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));

        self::assertTrue($result->reconciled);
        self::assertSame(['MarketBuy', 'DealItem'], array_column($http->operations(), 0));
    }

    #[Test]
    public function anUnexpectedThrowableFromTheSendIsReconciled(): void
    {
        $http = new FakeHttpClient($this->found('DealItem', 'order-42'));
        $api = $this->apiOverExecutor($http, new \UnexpectedValueException('send boom'), null);

        $result = $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));

        self::assertTrue($result->reconciled);
        self::assertSame(['DealItem'], array_column($http->operations(), 0), 'the send threw before the fake client; only the lookup went out');
    }

    #[Test]
    public function lookupsThatThrowUnexpectedlyEndInOutcomeUnknownWithThatError(): void
    {
        $api = $this->apiOverExecutor(new FakeHttpClient(), new \UnexpectedValueException('send boom'), new \UnexpectedValueException('lookup boom'));

        try {
            $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));
            self::fail('Expected PurchaseOutcomeUnknownException.');
        } catch (PurchaseOutcomeUnknownException $e) {
            self::assertSame('order-42', $e->customId);
            self::assertInstanceOf(\UnexpectedValueException::class, $e->getPrevious());
            self::assertInstanceOf(\UnexpectedValueException::class, $e->lastLookupError);
            self::assertSame('lookup boom', $e->lastLookupError->getMessage());
        }
    }

    /**
     * A BuyingApi whose `market_buy` call throws `$sendFailure` and whose lookups throw `$lookupFailure` (or go to the
     * real executor over `$http` when null): the outer boundary misbehaving in a way that is not a WhiteMarketException.
     */
    private function apiOverExecutor(FakeHttpClient $http, \Throwable $sendFailure, ?\Throwable $lookupFailure): BuyingApi
    {
        $factory = new HttpFactory();
        $real = new GraphQLExecutor(
            new HttpTransport(new HttpClients($http, $http), $factory, $factory, retryPolicy: new RetryPolicy(maxAttempts: 1), sleeper: new RecordingSleeper()),
            new BearerCredentials(new StaticAccessToken(new Secret('synthetic-access-token'))),
        );
        $executor = new class ($real, $sendFailure, $lookupFailure) implements OperationExecutor {
            public function __construct(private readonly OperationExecutor $real, private readonly \Throwable $sendFailure, private readonly ?\Throwable $lookupFailure) {}

            /** @param array<string, mixed> $variables */
            public function execute(string $document, string $operationName, OperationType $type, #[\SensitiveParameter] array $variables): array
            {
                if ($type === OperationType::Mutation) {
                    throw $this->sendFailure;
                }

                return $this->lookupFailure instanceof \Throwable ? throw $this->lookupFailure : $this->real->execute($document, $operationName, $type, $variables);
            }
        };

        return new BuyingApi(new GeneratedClient($executor), new ModelMapper(), new BuyReconciliation(delay: self::DELAY, attempts: 1, interval: self::INTERVAL), new RecordingSleeper());
    }

    #[Test]
    public function aRefreshableTokenRejectedTwiceIsAnAuthenticationErrorWithoutALookup(): void
    {
        $first = SyntheticJwt::issuedAt(new \DateTimeImmutable(), 86_400, 'first');
        $second = SyntheticJwt::issuedAt(new \DateTimeImmutable(), 86_400, 'second');
        $http = new FakeHttpClient(FakeHttpClient::authToken($first), FakeHttpClient::status(401), FakeHttpClient::authToken($second), FakeHttpClient::status(401));
        $client = WhiteMarketClient::create(partnerToken: 'synthetic-partner-token', httpClient: $http);

        try {
            $client->buying->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13');
            self::fail('The rejection must reach the caller.');
        } catch (AuthenticationException) {
        }

        // The executor's one refresh and replay of a rejected request (a 401 means it did not run), then nothing.
        self::assertSame(['AuthToken', 'MarketBuy', 'AuthToken', 'MarketBuy'], array_column($http->operations(), 0));
    }

    // ---- ambiguous failures: reconcile ------------------------------------------------------------------------

    /**
     * @return iterable<string, array{ResponseInterface|\Throwable, class-string<WhiteMarketException>}>
     */
    public static function ambiguousFailures(): iterable
    {
        yield 'HTTP 500' => [FakeHttpClient::status(500), GeneralServerException::class];
        yield 'HTTP 502' => [FakeHttpClient::status(502), GeneralServerException::class];
        yield 'HTTP 503' => [FakeHttpClient::status(503), ServiceUnavailableException::class];
        yield 'HTTP 503 with Retry-After' => [FakeHttpClient::status(503, '', ['Retry-After' => '30']), ServiceUnavailableException::class];
        yield 'HTTP 504' => [FakeHttpClient::status(504), GeneralServerException::class];
        yield 'network error or timeout' => [FakeHttpClient::networkError(), TransportException::class];
        yield 'an AuthenticationException thrown by the client (not a clean rejection)' => [new AuthenticationException(401, ''), TransportException::class];
        yield 'HTTP 200 with a body that is not JSON' => [FakeHttpClient::status(200, '<html>'), DecodeException::class];
        yield 'HTTP 200 with a deal that cannot be mapped' => [
            FakeHttpClient::json(['data' => ['market_buy' => ['price' => ['currency' => 'USD', 'value' => '1,5']] + SyntheticDeal::inProgress(self::BUY_NS, ['customId' => 'order-42'])]]),
            DecodeException::class,
        ];
    }

    /**
     */
    #[Test]
    #[DataProvider('ambiguousFailures')]
    public function looksTheDealUpOnceAfterTheDelayAndReturnsItReconciled(ResponseInterface|\Throwable $failure): void
    {
        [$api, $http, $sleeper] = $this->api(new FakeHttpClient($failure, $this->found('DealItem', 'order-42')));

        $result = $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));

        self::assertTrue($result->reconciled);
        self::assertSame('order-42', $result->customId);
        self::assertSame(SyntheticDeal::DEAL_ID, $result->deal->id);
        self::assertFalse($result->deal->isArchived());
        self::assertSame([self::DELAY], $sleeper->delays());
        self::assertSame(
            [['MarketBuy', ['customId' => 'order-42', 'id' => '7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13']], ['DealItem', ['customId' => 'order-42']]],
            $http->operations(),
            'one market_buy, then one lookup by the same customId',
        );
    }

    /**
     * @param class-string<WhiteMarketException> $cause
     */
    #[Test]
    #[DataProvider('ambiguousFailures')]
    public function failsWithTheOutcomeUnknownWhenReconciliationFindsNothing(ResponseInterface|\Throwable $failure, string $cause): void
    {
        $queue = [$failure];
        for ($round = 0; $round < 3; ++$round) {
            $queue[] = FakeHttpClient::json(self::NOT_FOUND);
            $queue[] = FakeHttpClient::json(self::NOT_FOUND);
        }
        [$api, $http, $sleeper] = $this->api(new FakeHttpClient(...$queue));

        try {
            $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));
            self::fail('The outcome is unknown.');
        } catch (PurchaseOutcomeUnknownException $e) {
            self::assertSame('order-42', $e->customId);
            self::assertInstanceOf($cause, $e->getPrevious(), 'the market_buy failure is the cause');
            self::assertInstanceOf(NotFoundException::class, $e->lastLookupError);
        }

        self::assertSame([self::DELAY, self::INTERVAL, self::INTERVAL, self::INTERVAL], $sleeper->delays());
        self::assertSame(
            ['MarketBuy', 'DealItem', 'DealHistoryItem', 'DealItem', 'DealHistoryItem', 'DealItem', 'DealHistoryItem'],
            array_column($http->operations(), 0),
            'market_buy is sent once; three rounds of two lookups',
        );
    }

    #[Test]
    public function findsAnArchivedDealOnALaterRound(): void
    {
        [$api, $http, $sleeper] = $this->api(new FakeHttpClient(
            FakeHttpClient::status(503),
            FakeHttpClient::json(self::NOT_FOUND),
            FakeHttpClient::json(self::NOT_FOUND),
            FakeHttpClient::json(self::NOT_FOUND),
            $this->found('DealHistoryItem', 'order-42'),
        ));

        $result = $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));

        self::assertTrue($result->reconciled);
        self::assertTrue($result->deal->isArchived());
        self::assertSame(DealStatus::SteamRollback, $result->deal->status);
        self::assertSame([self::DELAY, self::INTERVAL], $sleeper->delays());
        self::assertSame(['MarketBuy', 'DealItem', 'DealHistoryItem', 'DealItem', 'DealHistoryItem'], array_column($http->operations(), 0));
    }

    #[Test]
    public function aLookupThatFailsIsNotFoundForThatRoundAndTheLastErrorIsKept(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(
            FakeHttpClient::status(502),
            FakeHttpClient::networkError(),
            FakeHttpClient::status(500),
        ), new BuyReconciliation(attempts: 1));

        try {
            $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));
            self::fail('The outcome is unknown.');
        } catch (PurchaseOutcomeUnknownException $e) {
            self::assertInstanceOf(GeneralServerException::class, $e->getPrevious());
            self::assertInstanceOf(GeneralServerException::class, $e->lastLookupError);
            self::assertSame(500, $e->lastLookupError->status, 'the last lookup (the archive), not the first');
        }

        self::assertCount(3, $http->requests());
    }

    /**
     * @return iterable<string, array{ResponseInterface|\Throwable, class-string<WhiteMarketException>}>
     */
    public static function failedDealItemLookups(): iterable
    {
        yield 'HTTP 500' => [FakeHttpClient::status(500), GeneralServerException::class];
        yield 'network error' => [FakeHttpClient::networkError(), TransportException::class];
    }

    /**
     * @param class-string<WhiteMarketException> $expected
     */
    #[Test]
    #[DataProvider('failedDealItemLookups')]
    public function aFailedLookupIsKeptOverALaterNotFound(ResponseInterface|\Throwable $failure, string $expected): void
    {
        [$api] = $this->api(new FakeHttpClient(FakeHttpClient::status(500), $failure, FakeHttpClient::json(self::NOT_FOUND)), new BuyReconciliation(attempts: 1));

        try {
            $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));
            self::fail('The outcome is unknown.');
        } catch (PurchaseOutcomeUnknownException $e) {
            self::assertInstanceOf($expected, $e->lastLookupError, 'deal_item failed, the archive only said not found');
        }
    }

    #[Test]
    public function aFoundDealThatCannotBeMappedCountsAsALookupFailure(): void
    {
        $broken = ['price' => ['currency' => 'USD', 'value' => '1,5']] + SyntheticDeal::inProgress(DealItem::class, ['customId' => 'order-42']);
        [$api] = $this->api(new FakeHttpClient(
            FakeHttpClient::status(500),
            FakeHttpClient::json(['data' => ['deal_item' => $broken]]),
            $this->found('DealHistoryItem', 'order-42'),
        ));

        $result = $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));

        self::assertTrue($result->deal->isArchived());
    }

    #[Test]
    public function honoursACustomReconciliation(): void
    {
        [$api, $http, $sleeper] = $this->api(new FakeHttpClient(
            FakeHttpClient::status(500),
            FakeHttpClient::json(self::NOT_FOUND),
            FakeHttpClient::json(self::NOT_FOUND),
        ), new BuyReconciliation(delay: 10.0, attempts: 1, interval: 0.0));

        try {
            $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));
            self::fail('The outcome is unknown.');
        } catch (PurchaseOutcomeUnknownException) {
        }

        self::assertSame([10.0, 0.0], $sleeper->delays());
        self::assertCount(3, $http->requests());
    }

    #[Test]
    public function reconcilesWithTheGeneratedCustomId(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::status(500), $this->found('DealItem', 'whatever')));

        $result = $api->buyByAssetId(App::Csgo, '40000000000000000002');

        self::assertMatchesRegularExpression(self::UUID_V4, $result->customId);
        self::assertSame($result->customId, $this->variablesOf($http, 0)['customId'] ?? null);
        self::assertSame(['customId' => $result->customId], $this->variablesOf($http, 1));
    }

    #[Test]
    public function theOutcomeUnknownErrorCarriesTheGeneratedCustomId(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::status(500), ...array_fill(0, 6, FakeHttpClient::json(self::NOT_FOUND))));

        try {
            $api->buyByAssetId(App::Csgo, '40000000000000000002');
            self::fail('The outcome is unknown.');
        } catch (PurchaseOutcomeUnknownException $e) {
            self::assertSame($this->variablesOf($http, 0)['customId'] ?? null, $e->customId);
            self::assertMatchesRegularExpression(self::UUID_V4, $e->customId);
        }
    }

    #[Test]
    public function neverRetriesMarketBuyEvenWithTheDefaultRetryPolicy(): void
    {
        [$api, $http] = $this->api(new FakeHttpClient(FakeHttpClient::status(503), $this->found('DealItem', 'order-42')), retry: new RetryPolicy());

        $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', options: new BuyOptions(customId: 'order-42'));

        self::assertSame(['MarketBuy', 'DealItem'], array_column($http->operations(), 0));
    }

    // ---- input validation: before anything is sent -------------------------------------------------------------

    /**
     * @return iterable<string, array{string}> the call ({@see buyWithMaxPrice()})
     */
    public static function callsWithAMaxPrice(): iterable
    {
        yield 'buyProduct' => ['product'];
        yield 'buyCheapest' => ['cheapest'];
        yield 'buyByAssetId' => ['asset'];
    }

    /**
     * @throws WhiteMarketException
     */
    private function buyWithMaxPrice(BuyingApi $api, string $call, ?Money $maxPrice): BuyResult
    {
        $options = new BuyOptions(customId: 'order-42');

        return match ($call) {
            'product' => $api->buyProduct('7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13', $maxPrice, $options),
            'cheapest' => $api->buyCheapest(App::Csgo, 'AK-47 | Redline (Field-Tested)', $maxPrice, options: $options),
            'asset' => $api->buyByAssetId(App::Csgo, '40000000000000000002', $maxPrice, $options),
            default => throw new \LogicException('Unknown call ' . $call),
        };
    }

    #[Test]
    #[DataProvider('callsWithAMaxPrice')]
    public function sendsTheMaxPriceAsAnExactDecimalWhenGiven(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->bought('order-42')));

        $this->buyWithMaxPrice($api, $call, TestMoney::usd('12.50'));

        self::assertSame(['currency' => 'USD', 'value' => '12.50'], $this->variablesOf($http, 0)['maxPriceInput'] ?? null);
    }

    #[Test]
    #[DataProvider('callsWithAMaxPrice')]
    public function omitsTheMaxPriceKeyWhenNoneIsGiven(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient($this->bought('order-42')));

        $this->buyWithMaxPrice($api, $call, null);

        self::assertArrayNotHasKey('maxPriceInput', $this->variablesOf($http, 0));
    }

    #[Test]
    #[DataProvider('callsWithAMaxPrice')]
    public function rejectsAMaxPriceWithAnUnknownCurrencyBeforeSending(string $call): void
    {
        [$api, $http] = $this->api(new FakeHttpClient());

        try {
            $this->buyWithMaxPrice($api, $call, new Money(Decimal::of('5'), new UnknownEnumValue(Currency::class, 'EUR')));
            self::fail('The currency cannot be sent.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Unknown currency "EUR" cannot be sent.', $e->getMessage());
        }

        self::assertSame([], $http->requests());
    }

    // ---- helpers -----------------------------------------------------------------------------------------------

    /**
     * The variables of request number `$index`.
     *
     * @return array<string, mixed>
     */
    private function variablesOf(FakeHttpClient $http, int $index): array
    {
        return ($http->operations()[$index] ?? throw new \LogicException('No request #' . $index))[1];
    }

    private function bought(string $customId): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['market_buy' => SyntheticDeal::inProgress(self::BUY_NS, ['customId' => $customId])]]);
    }

    /**
     * @param 'DealItem'|'DealHistoryItem' $operation
     */
    private function found(string $operation, string $customId): ResponseInterface
    {
        return $operation === 'DealItem'
            ? FakeHttpClient::json(['data' => ['deal_item' => SyntheticDeal::inProgress(DealItem::class, ['customId' => $customId])]])
            : FakeHttpClient::json(['data' => ['deal_history_item' => SyntheticDeal::archived(DealHistoryItem::class, ['customId' => $customId])]]);
    }
}
