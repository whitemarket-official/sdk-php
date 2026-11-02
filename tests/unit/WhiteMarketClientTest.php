<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LogLevel;
use WhiteMarket\Sdk\Auth\AuthApi;
use WhiteMarket\Sdk\Auth\InMemoryTokenStore;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\TokenProvider;
use WhiteMarket\Sdk\Auth\TokenStore;
use WhiteMarket\Sdk\Buying\BuyingApi;
use WhiteMarket\Sdk\Buying\BuyReconciliation;
use WhiteMarket\Sdk\Deals\DealsApi;
use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\ErrorCategory;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Export\ExportKind;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Export\NotModified;
use WhiteMarket\Sdk\Generated\Operation\Query\DealItem;
use WhiteMarket\Sdk\Generated\Operation\Query\MarketItem\Result as MarketItemResult;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveTransport;
use WhiteMarket\Sdk\Market\MarketApi;
use WhiteMarket\Sdk\Selling\SellingApi;
use WhiteMarket\Sdk\Tests\Support\ArrayCache;
use WhiteMarket\Sdk\Tests\Support\ChunkedStream;
use WhiteMarket\Sdk\Tests\Support\DebugOutput;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\LiveFixture;
use WhiteMarket\Sdk\Tests\Support\LoopbackServer;
use WhiteMarket\Sdk\Tests\Support\MemoryLogger;
use WhiteMarket\Sdk\Tests\Support\SyntheticDeal;
use WhiteMarket\Sdk\Tests\Support\SyntheticJwt;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;
use WhiteMarket\Sdk\Tests\Support\TestExport;
use WhiteMarket\Sdk\Tests\Support\ThrowingLogger;
use WhiteMarket\Sdk\Transport\HttpClients;
use WhiteMarket\Sdk\Transport\TransportOptions;
use WhiteMarket\Sdk\WhiteMarketClient;

/**
 * @phpstan-type CreateOptions array{partnerToken?: string, accessToken?: string, tokenProvider?: TokenProvider, tokenStore?: TokenStore}
 */
#[CoversClass(WhiteMarketClient::class)]
#[CoversClass(AuthApi::class)]
#[CoversClass(MarketApi::class)]
#[CoversClass(BuyingApi::class)]
#[CoversClass(DealsApi::class)]
#[CoversClass(SellingApi::class)]
final class WhiteMarketClientTest extends TestCase
{
    private const PARTNER_TOKEN = 'synthetic-partner-token-3c9d';

    private const ACCESS_TOKEN = 'synthetic-access-token-51be';

    /** @var array<string, false|string> environment values before the test */
    private array $environment = [];

    protected function setUp(): void
    {
        foreach ([WhiteMarketClient::PARTNER_TOKEN_ENV, WhiteMarketClient::BASE_URL_ENV] as $name) {
            $this->environment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    #[Test]
    public function aLoggerThatThrowsDoesNotChangeTheOutcomeOfACall(): void
    {
        $logger = new ThrowingLogger();
        $http = new FakeHttpClient($this->pong());

        $answer = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http, logger: $logger)->auth->ping();

        self::assertSame('pong', $answer);
        self::assertNotSame([], $logger->calls(), 'the transport did log, and the logger did throw');
    }

    #[Test]
    public function exchangesThePartnerTokenAndPingsWithTheAccessToken(): void
    {
        $jwt = $this->jwt();
        $http = new FakeHttpClient(FakeHttpClient::authToken($jwt), $this->pong());

        $answer = WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN, httpClient: $http)->auth->ping();

        self::assertSame('pong', $answer);
        self::assertSame(
            [['AuthToken', self::PARTNER_TOKEN, ''], ['TestPing', '', 'Bearer ' . $jwt]],
            $this->sent($http),
        );
    }

    #[Test]
    public function theGeneratedLayerSharesTheClientsTokenAndTransport(): void
    {
        $jwt = $this->jwt();
        $http = new FakeHttpClient(FakeHttpClient::authToken($jwt), $this->pong(), $this->pong());
        $client = WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN, httpClient: $http);

        $typed = $client->generated->testPing();
        $raw = $client->generated->execute('query Ping { test_ping }');

        self::assertSame('pong', $typed);
        self::assertSame(['test_ping' => 'pong'], $raw);
        self::assertSame(
            [['AuthToken', self::PARTNER_TOKEN, ''], ['TestPing', '', 'Bearer ' . $jwt], ['Ping', '', 'Bearer ' . $jwt]],
            $this->sent($http),
        );
    }

    #[Test]
    public function sendsAGivenAccessTokenWithoutAnExchange(): void
    {
        $http = new FakeHttpClient($this->pong());

        WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http)->auth->ping();

        self::assertSame([['TestPing', '', 'Bearer ' . self::ACCESS_TOKEN]], $this->sent($http));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidAccessTokens(): iterable
    {
        yield 'CR LF' => ["synthetic-token\r\nX-Injected: 1"];
        yield 'space' => ['synthetic token'];
    }

    #[Test]
    #[DataProvider('invalidAccessTokens')]
    public function refusesAGivenAccessTokenThatIsNotAValidBearerTokenAtCreate(string $token): void
    {
        try {
            WhiteMarketClient::create(accessToken: $token, httpClient: new FakeHttpClient());
            self::fail('create() accepted the token');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('accessToken is not a valid bearer token (RFC 6750 b64token).', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPartnerTokens(): iterable
    {
        yield 'trailing LF' => ["synthetic-partner-token\n"];
        yield 'CR LF inside' => ["synthetic-partner\r\nX-Injected: 1"];
        yield 'leading space' => [' synthetic-partner-token'];
        yield 'trailing space' => ['synthetic-partner-token '];
        yield 'tab' => ["synthetic\tpartner-token"];
        yield 'non-ASCII' => ["synthetic-partner-t\u{00F6}ken"];
    }

    #[Test]
    #[DataProvider('invalidPartnerTokens')]
    public function refusesAPartnerTokenThatIsNotAValidHeaderValueAtCreate(string $token): void
    {
        $http = new FakeHttpClient();

        try {
            WhiteMarketClient::create(partnerToken: $token, httpClient: $http);
            self::fail('create() accepted the token');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('partnerToken', $e->getMessage());
            self::assertStringNotContainsString(trim($token), $e->getMessage());
        }
        self::assertSame([], $http->requests());
    }

    #[Test]
    #[DataProvider('invalidPartnerTokens')]
    public function refusesAnInvalidPartnerTokenFromTheEnvironment(string $token): void
    {
        putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . $token);
        $http = new FakeHttpClient();

        try {
            WhiteMarketClient::fromEnv(httpClient: $http);
            self::fail('fromEnv() accepted the token');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(WhiteMarketClient::PARTNER_TOKEN_ENV, $e->getMessage());
            self::assertStringContainsString('trailing newline', $e->getMessage());
            self::assertStringNotContainsString(trim($token), $e->getMessage());
        }
        self::assertSame([], $http->requests());
    }

    #[Test]
    public function acceptsAPartnerTokenWithAnInnerSpace(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::authToken($this->jwt()));

        WhiteMarketClient::create(partnerToken: 'synthetic partner token', httpClient: $http)->auth->accessToken();

        self::assertSame('synthetic partner token', $http->lastRequest()->getHeaderLine('X-partner-token'));
    }

    #[Test]
    public function sendsTheTokenOfAGivenProvider(): void
    {
        $http = new FakeHttpClient($this->pong());
        $provider = new class implements TokenProvider {
            public function accessToken(): Secret
            {
                return new Secret('synthetic-provider-token');
            }

            public function refresh(): Secret
            {
                return new Secret('synthetic-provider-token-renewed');
            }
        };

        $client = WhiteMarketClient::create(tokenProvider: $provider, httpClient: $http);
        $client->auth->ping();

        self::assertSame([['TestPing', '', 'Bearer synthetic-provider-token']], $this->sent($http));
        self::assertSame('synthetic-provider-token-renewed', $client->auth->refresh()->reveal());
    }

    #[Test]
    public function exchangesThePartnerTokenAgainAndReplaysAfterA401(): void
    {
        $first = $this->jwt('first');
        $second = $this->jwt('second');
        $http = new FakeHttpClient(FakeHttpClient::authToken($first), FakeHttpClient::status(401), FakeHttpClient::authToken($second), $this->pong());

        $answer = WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN, httpClient: $http)->auth->ping();

        self::assertSame('pong', $answer);
        self::assertSame(
            [['AuthToken', self::PARTNER_TOKEN, ''], ['TestPing', '', 'Bearer ' . $first], ['AuthToken', self::PARTNER_TOKEN, ''], ['TestPing', '', 'Bearer ' . $second]],
            $this->sent($http),
        );
    }

    #[Test]
    public function letsA401ReachTheCallerOfAFixedAccessToken(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(401));

        $this->expectException(AuthenticationException::class);

        try {
            WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http)->auth->ping();
        } finally {
            self::assertCount(1, $http->requests());
        }
    }

    #[Test]
    public function cachesTheAccessTokenUntilARefreshIsForced(): void
    {
        $first = $this->jwt('first');
        $second = $this->jwt('second');
        $http = new FakeHttpClient(FakeHttpClient::authToken($first), FakeHttpClient::authToken($second));
        $auth = WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN, httpClient: $http)->auth;

        $tokens = [$auth->accessToken()->reveal(), $auth->accessToken()->reveal(), $auth->refresh()->reveal(), $auth->accessToken()->reveal()];

        self::assertSame([$first, $first, $second, $second], $tokens);
        self::assertCount(2, $http->requests());
    }

    #[Test]
    public function aDumpOfTheClientRevealsNeitherThePartnerTokenNorTheCachedAccessToken(): void
    {
        $jwt = $this->jwt('dumped');
        // Unlike FakeHttpClient, this client keeps no sent request: the dump shows only what the SDK itself holds.
        $http = new class (FakeHttpClient::authToken($jwt)) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };
        $client = WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN, httpClient: $http);
        $client->auth->accessToken();

        $outputs = DebugOutput::all($client);
        try {
            $outputs['serialize'] = serialize($client);
        } catch (\Exception) {
            // Refused (a Secret or an anonymous class inside): no serialized form exists to leak.
        }

        foreach ($outputs as $function => $output) {
            self::assertStringNotContainsString(self::PARTNER_TOKEN, $output, $function);
            self::assertStringNotContainsString($jwt, $output, $function);
        }
        self::assertNotSame('', $outputs['var_dump']);
    }

    #[Test]
    public function returnsAFixedAccessTokenOnRefreshWithoutARequest(): void
    {
        $http = new FakeHttpClient();
        $auth = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http)->auth;

        self::assertSame([self::ACCESS_TOKEN, self::ACCESS_TOKEN], [$auth->accessToken()->reveal(), $auth->refresh()->reveal()]);
        self::assertSame([], $http->requests());
    }

    #[Test]
    public function keepsTheExchangedTokenInTheGivenStore(): void
    {
        $jwt = $this->jwt();
        $store = new InMemoryTokenStore();

        WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN, tokenStore: $store, httpClient: new FakeHttpClient(FakeHttpClient::authToken($jwt)))->auth->accessToken();

        self::assertSame($jwt, $store->get()?->token->reveal());
    }

    #[Test]
    public function reusesATokenAnotherClientStored(): void
    {
        $jwt = $this->jwt();
        $store = new InMemoryTokenStore();
        WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN, tokenStore: $store, httpClient: new FakeHttpClient(FakeHttpClient::authToken($jwt)))->auth->accessToken();
        $http = new FakeHttpClient($this->pong());

        WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN, tokenStore: $store, httpClient: $http)->auth->ping();

        self::assertSame([['TestPing', '', 'Bearer ' . $jwt]], $this->sent($http));
    }

    #[Test]
    public function exposesTheBuyingModuleWithTheDefaultReconciliation(): void
    {
        $client = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: new FakeHttpClient());

        $reconciliation = $this->reach($client, ['buying', 'reconciliation']);

        self::assertInstanceOf(BuyReconciliation::class, $reconciliation);
        self::assertSame([15.0, 3, 5.0], [$reconciliation->delay, $reconciliation->attempts, $reconciliation->interval]);
    }

    #[Test]
    public function givesTheBuyingModuleTheGivenReconciliation(): void
    {
        $given = new BuyReconciliation(delay: 11.0, attempts: 2, interval: 1.0);

        $client = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: new FakeHttpClient(), buyReconciliation: $given);

        self::assertSame($given, $this->reach($client, ['buying', 'reconciliation']));
    }

    #[Test]
    public function fromEnvForwardsTheReconciliation(): void
    {
        putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . self::PARTNER_TOKEN);
        $given = new BuyReconciliation(delay: 11.0, attempts: 2, interval: 1.0);

        $client = WhiteMarketClient::fromEnv(httpClient: new FakeHttpClient(), buyReconciliation: $given);

        self::assertSame($given, $this->reach($client, ['buying', 'reconciliation']));
    }

    #[Test]
    public function liveReturnsANewSessionPerCall(): void
    {
        $client = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: new FakeHttpClient());

        self::assertNotSame($client->live(), $client->live());
    }

    #[Test]
    public function liveOverSseUsesTheInjectedClientWithTheClientsTokenAndOrigin(): void
    {
        $body = "data: {\"connect\":{\"client\":\"c1\",\"ping\":25,\"ttl\":86400,\"expires\":true,\"subs\":{}}}\n\n"
            . 'data: ' . trim(LiveFixture::raw('ws_product_added.json')) . "\n\n";
        $http = new FakeHttpClient(new Response(200, ['Content-Type' => 'text/event-stream'], ChunkedStream::of($body, 4096)));
        $client = WhiteMarketClient::create(
            accessToken: self::ACCESS_TOKEN,
            transport: new TransportOptions('https://api.example.test'),
            httpClient: $http,
        );
        $live = $client->live(new LiveOptions(transport: LiveTransport::Sse));
        $live->subscribeProducts();

        $event = $live->poll(5.0);

        self::assertInstanceOf(ProductAdded::class, $event);
        $request = $http->lastRequest();
        self::assertSame('https', $request->getUri()->getScheme());
        self::assertSame('api.example.test', $request->getUri()->getHost());
        self::assertSame('/sse_endpoint', $request->getUri()->getPath());
        parse_str($request->getUri()->getQuery(), $query);
        $connect = $query['cf_connect'] ?? null;
        self::assertIsString($connect);
        self::assertSame(
            ['token' => self::ACCESS_TOKEN, 'subs' => ['market_products_updates' => []]],
            json_decode($connect, true, 8, \JSON_THROW_ON_ERROR),
        );
        $live->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function plainBaseUrls(): iterable
    {
        yield 'http' => ['http://api.example.test'];
        yield 'ws' => ['ws://api.example.test'];
        yield 'wss' => ['wss://api.example.test'];
        yield 'http localhost' => ['http://localhost:8080'];
    }

    #[Test]
    #[DataProvider('plainBaseUrls')]
    public function liveIsTlsOnlyBecauseNoPlainBaseUrlCanBeConfigured(string $baseUrl): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // The only base URL the live factory ever receives is TransportOptions::baseUrl, which refuses non-https.
        WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, transport: new TransportOptions($baseUrl), httpClient: new FakeHttpClient())->live();
    }

    #[Test]
    public function liveRefusesAPlainBaseUrlSetThroughWithBaseUrlToo(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        WhiteMarketClient::create(
            accessToken: self::ACCESS_TOKEN,
            transport: (new TransportOptions('https://api.example.test'))->withBaseUrl('http://api.example.test'),
            httpClient: new FakeHttpClient(),
        )->live();
    }

    #[Test]
    public function liveUsesAWebSocketByDefault(): void
    {
        $client = WhiteMarketClient::create(
            accessToken: self::ACCESS_TOKEN,
            transport: new TransportOptions('https://localhost:1'),
            httpClient: new FakeHttpClient(),
        );
        $live = $client->live(new LiveOptions(connectTimeout: 2.0));
        $errors = [];
        $live->onError(static function (\Throwable $e) use (&$errors): void {
            $errors[] = $e->getMessage();
        });

        self::assertNull($live->poll(0.0));
        $live->close();

        self::assertCount(1, $errors);
        self::assertStringContainsString('WebSocket', $errors[0]);
        self::assertStringNotContainsString(self::ACCESS_TOKEN, $errors[0]);
    }

    #[Test]
    public function exposesAStandaloneExportClientOverTheGivenHttpClient(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.meta.json')));

        $client = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http, exportOptions: new ExportOptions('https://export.example.test', userAgentSuffix: 'app/1.0'));
        $meta = $client->exports->meta(ExportKind::Prices);

        self::assertSame(4, $meta->items);
        self::assertSame('https://export.example.test/v1/prices/730.json.meta.json', (string) $http->lastRequest()->getUri());
        self::assertStringEndsWith(' app/1.0', $http->lastRequest()->getHeaderLine('User-Agent'));
        self::assertFalse($http->lastRequest()->hasHeader('Authorization'), 'the export is public: no credential travels with it');
    }

    #[Test]
    public function givesTheExportClientTheExportCache(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.json'), ['ETag' => '"v1"']), FakeHttpClient::status(304));
        $client = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http, exportCache: new ArrayCache());

        $client->exports->prices(conditional: true);

        self::assertInstanceOf(NotModified::class, $client->exports->prices(conditional: true));
    }

    #[Test]
    public function fromEnvForwardsTheExportOptionsAndCache(): void
    {
        putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . self::PARTNER_TOKEN);
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.json'), ['ETag' => '"v1"']), FakeHttpClient::status(304));

        $client = WhiteMarketClient::fromEnv(httpClient: $http, exportOptions: new ExportOptions('https://export.example.test'), exportCache: new ArrayCache());
        $client->exports->prices(conditional: true);
        $second = $client->exports->prices(conditional: true);

        self::assertInstanceOf(NotModified::class, $second);
        self::assertSame('https://export.example.test/v1/prices/730.json', $second->url);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modulesOnTheSharedGeneratedClient(): iterable
    {
        foreach (['inventory', 'wallet', 'profile', 'store', 'selling', 'deals', 'buying'] as $module) {
            yield $module => [$module];
        }
    }

    #[Test]
    #[DataProvider('modulesOnTheSharedGeneratedClient')]
    public function aModuleUsesTheSharedGeneratedClient(string $module): void
    {
        $client = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: new FakeHttpClient());

        self::assertSame($this->reach($client, ['market', 'api']), $this->reach($client, [$module, 'api']), 'one generated client per WhiteMarketClient');
    }

    #[Test]
    public function exposesTheSellingModuleWithTheDefaultBatchSize(): void
    {
        $client = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: new FakeHttpClient());

        self::assertSame(100, $this->reach($client, ['selling', 'batchSize']));
    }

    #[Test]
    public function givesTheSellingModuleTheGivenBatchSize(): void
    {
        $client = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: new FakeHttpClient(), sellingBatchSize: 25);

        self::assertSame(25, $this->reach($client, ['selling', 'batchSize']));
    }

    #[Test]
    public function fromEnvForwardsTheSellingBatchSize(): void
    {
        putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . self::PARTNER_TOKEN);

        $client = WhiteMarketClient::fromEnv(httpClient: new FakeHttpClient(), sellingBatchSize: 25);

        self::assertSame(25, $this->reach($client, ['selling', 'batchSize']));
    }

    #[Test]
    public function refusesABatchSizeBelowOne(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The batch size must be at least 1.');

        (new \ReflectionMethod(WhiteMarketClient::class, 'create'))->invokeArgs(null, ['accessToken' => self::ACCESS_TOKEN, 'httpClient' => new FakeHttpClient(), 'sellingBatchSize' => 0]);
    }

    #[Test]
    public function sellingModuleGoesThroughTheSameTransportAndToken(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => ['market_lifetime_all' => true]]));

        $done = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http)->selling->setLifetimeForAll(600);

        self::assertTrue($done);
        self::assertSame([['MarketLifetimeAll', '', 'Bearer ' . self::ACCESS_TOKEN]], $this->sent($http));
        self::assertSame(['value' => 600], $http->lastPayload()['variables'] ?? null);
    }

    #[Test]
    public function marketModuleGoesThroughTheSameTransportAndToken(): void
    {
        $node = SyntheticResult::of(MarketItemResult::class, ['slug' => 'ak-47-redline']);
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => ['market_item' => $node]]));

        $product = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http)->market->get('ak-47-redline');

        self::assertSame('ak-47-redline', $product->slug);
        self::assertSame([['MarketItem', '', 'Bearer ' . self::ACCESS_TOKEN]], $this->sent($http));
        self::assertSame(['idOrSlug' => 'ak-47-redline'], $http->lastPayload()['variables'] ?? null);
    }

    #[Test]
    public function dealsModuleGoesThroughTheSameTransportAndToken(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => ['deal_item' => SyntheticDeal::inProgress(DealItem::class, ['customId' => 'order-42'])]]));

        $deal = WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http)->deals->get('order-42');

        self::assertSame([SyntheticDeal::DEAL_ID, 'order-42'], [$deal->id, $deal->customId]);
        self::assertSame([['DealItem', '', 'Bearer ' . self::ACCESS_TOKEN]], $this->sent($http));
        self::assertSame(['customId' => 'order-42'], $http->lastPayload()['variables'] ?? null);
    }

    #[Test]
    public function aMissingListingIsANotFoundException(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::json(['data' => null, 'errors' => [
            ['message' => 'Product not found', 'path' => ['market_item'], 'extensions' => ['category' => ErrorCategory::MARKET_PRODUCT_NOT_FOUND]],
        ]]));

        $this->expectException(NotFoundException::class);

        WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: $http)->market->get('gone');
    }

    /**
     * @return iterable<string, array{CreateOptions, string}>
     */
    public static function invalidCredentials(): iterable
    {
        $provider = new class implements TokenProvider {
            public function accessToken(): Secret
            {
                return new Secret('unused');
            }

            public function refresh(): Secret
            {
                return new Secret('unused');
            }
        };

        yield 'none' => [[], 'exactly one'];
        yield 'partner and access token' => [['partnerToken' => self::PARTNER_TOKEN, 'accessToken' => self::ACCESS_TOKEN], 'exactly one'];
        yield 'access token and provider' => [['accessToken' => self::ACCESS_TOKEN, 'tokenProvider' => $provider], 'exactly one'];
        yield 'partner token and provider' => [['partnerToken' => self::PARTNER_TOKEN, 'tokenProvider' => $provider], 'exactly one'];
        yield 'all three' => [['partnerToken' => self::PARTNER_TOKEN, 'accessToken' => self::ACCESS_TOKEN, 'tokenProvider' => $provider], 'exactly one'];
        yield 'store with an access token' => [['accessToken' => self::ACCESS_TOKEN, 'tokenStore' => new InMemoryTokenStore()], 'tokenStore'];
        yield 'store with a provider' => [['tokenProvider' => $provider, 'tokenStore' => new InMemoryTokenStore()], 'tokenStore'];
        yield 'store alone' => [['tokenStore' => new InMemoryTokenStore()], 'tokenStore'];
        yield 'empty partner token' => [['partnerToken' => ''], 'must not be empty'];
        yield 'empty access token' => [['accessToken' => ''], 'must not be empty'];
    }

    /**
     * @param CreateOptions $options
     */
    #[Test]
    #[DataProvider('invalidCredentials')]
    public function rejectsAnythingButExactlyOneCredential(array $options, string $message): void
    {
        $http = new FakeHttpClient();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        try {
            WhiteMarketClient::create(...$options, httpClient: $http);
        } finally {
            self::assertSame([], $http->requests());
        }
    }

    #[Test]
    public function sendsToTheConfiguredEndpoint(): void
    {
        $http = new FakeHttpClient($this->pong());

        WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, transport: new TransportOptions('https://api.example.test', '/graphql/custom'), httpClient: $http)->auth->ping();

        self::assertSame('https://api.example.test/graphql/custom', (string) $http->lastRequest()->getUri());
    }

    #[Test]
    public function logsANoticeThatAnInjectedClientKeepsItsOwnTimeouts(): void
    {
        $logger = new MemoryLogger();

        WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, httpClient: new FakeHttpClient(), logger: $logger);

        self::assertSame(
            [[LogLevel::NOTICE, true]],
            array_map(static fn(array $record): array => [$record['level'], str_contains($record['message'], 'timeouts are not applied')], $logger->records()),
        );
    }

    #[Test]
    public function logsNothingWithTheDefaultClient(): void
    {
        $logger = new MemoryLogger();

        WhiteMarketClient::create(accessToken: self::ACCESS_TOKEN, logger: $logger);

        self::assertSame([], $logger->records());
    }

    /**
     * @return iterable<string, array{0: list<string>, 1: string, 2: list<string>, 3?: bool}>
     */
    public static function defaultMutationClients(): iterable
    {
        $paths = ['API requests' => ['api', 'executor', 'transport', 'clients'], 'auth_token exchange' => ['provider', 'exchange', 'executor', 'transport', 'clients']];
        foreach ($paths as $name => $path) {
            foreach (LoopbackServer::connectionsClosedWithoutAResponse() as $scenario => $arguments) {
                yield $name . ', ' . $scenario => [$path, ...$arguments];
            }
        }
    }

    /**
     * The transport is reached by reflection because no request can show it: the default clients only speak https to
     * real hosts, and the loopback server is plain http. What is asserted is how the client the SDK built behaves.
     *
     * @param list<string> $path private properties from `$client->auth` to the transport's {@see HttpClients}
     * @param list<string> $bodies
     */
    #[Test]
    #[DataProvider('defaultMutationClients')]
    public function sendsEachMutationOnceWhenNoClientIsInjected(array $path, string $mode, array $bodies, bool $bodyReadBeforeSending = false): void
    {
        $clients = $this->reach(WhiteMarketClient::create(partnerToken: self::PARTNER_TOKEN)->auth, $path);
        self::assertInstanceOf(HttpClients::class, $clients);

        $received = LoopbackServer::bodiesReceived($mode, $clients->mutation, $bodies, $bodyReadBeforeSending);

        self::assertSame(implode("\n", $bodies) . "\n", $received);
    }

    #[Test]
    public function readsThePartnerTokenFromTheEnvironment(): void
    {
        putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . self::PARTNER_TOKEN);
        $http = new FakeHttpClient(FakeHttpClient::authToken($this->jwt()));

        WhiteMarketClient::fromEnv(httpClient: $http)->auth->accessToken();

        self::assertSame(self::PARTNER_TOKEN, $http->lastRequest()->getHeaderLine('X-partner-token'));
        self::assertSame('https://api.white.market/graphql/partner', (string) $http->lastRequest()->getUri());
    }

    #[Test]
    public function takesTheBaseUrlFromTheEnvironmentAndKeepsTheOtherTransportOptions(): void
    {
        putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . self::PARTNER_TOKEN);
        putenv(WhiteMarketClient::BASE_URL_ENV . '=https://staging.example.test');
        $http = new FakeHttpClient(FakeHttpClient::authToken($this->jwt()));

        WhiteMarketClient::fromEnv(transport: new TransportOptions('https://api.example.test', '/graphql/custom', userAgentSuffix: 'app/1.0'), httpClient: $http)->auth->accessToken();

        self::assertSame('https://staging.example.test/graphql/custom', (string) $http->lastRequest()->getUri());
        self::assertStringEndsWith(' app/1.0', $http->lastRequest()->getHeaderLine('User-Agent'));
    }

    #[Test]
    public function keepsTheTransportBaseUrlWhenTheEnvironmentHasAnEmptyOne(): void
    {
        putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . self::PARTNER_TOKEN);
        putenv(WhiteMarketClient::BASE_URL_ENV . '=');
        $http = new FakeHttpClient(FakeHttpClient::authToken($this->jwt()));

        WhiteMarketClient::fromEnv(transport: new TransportOptions('https://api.example.test'), httpClient: $http)->auth->accessToken();

        self::assertSame('https://api.example.test/graphql/partner', (string) $http->lastRequest()->getUri());
    }

    #[Test]
    public function rejectsABaseUrlFromTheEnvironmentThatIsNotHttps(): void
    {
        putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . self::PARTNER_TOKEN);
        putenv(WhiteMarketClient::BASE_URL_ENV . '=http://api.example.test');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('https://');

        WhiteMarketClient::fromEnv(httpClient: new FakeHttpClient());
    }

    /**
     * @return iterable<string, array{string|null}>
     */
    public static function missingPartnerTokens(): iterable
    {
        yield 'not set' => [null];
        yield 'empty' => [''];
    }

    #[Test]
    #[DataProvider('missingPartnerTokens')]
    public function refusesToStartWithoutAPartnerTokenInTheEnvironment(?string $value): void
    {
        if ($value !== null) {
            putenv(WhiteMarketClient::PARTNER_TOKEN_ENV . '=' . $value);
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(WhiteMarketClient::PARTNER_TOKEN_ENV . ' is not set.');

        WhiteMarketClient::fromEnv(httpClient: new FakeHttpClient());
    }

    /**
     * @param list<string> $path
     */
    private function reach(object $object, array $path): mixed
    {
        $value = $object;
        foreach ($path as $property) {
            self::assertIsObject($value);
            $value = (new \ReflectionProperty($value, $property))->getValue($value);
        }

        return $value;
    }

    private function jwt(string $subject = 'synthetic-subject'): string
    {
        return SyntheticJwt::issuedAt(new \DateTimeImmutable(), 86_400, $subject);
    }

    private function pong(): ResponseInterface
    {
        return FakeHttpClient::json(['data' => ['test_ping' => 'pong']]);
    }

    /**
     * Each request as [operation name, X-partner-token, Authorization].
     *
     * @return list<array{mixed, string, string}>
     */
    private function sent(FakeHttpClient $http): array
    {
        return array_map(
            static function (RequestInterface $request): array {
                $payload = json_decode((string) $request->getBody(), true, 512, \JSON_THROW_ON_ERROR);

                return [\is_array($payload) ? $payload['operationName'] ?? null : null, $request->getHeaderLine('X-partner-token'), $request->getHeaderLine('Authorization')];
            },
            $http->requests(),
        );
    }
}
