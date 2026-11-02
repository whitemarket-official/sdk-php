<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Psr\SimpleCache\CacheInterface;
use WhiteMarket\Sdk\Auth\AuthApi;
use WhiteMarket\Sdk\Auth\BearerCredentials;
use WhiteMarket\Sdk\Auth\BearerToken;
use WhiteMarket\Sdk\Auth\HeaderValue;
use WhiteMarket\Sdk\Auth\InMemoryTokenStore;
use WhiteMarket\Sdk\Auth\PartnerTokenProvider;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\StaticAccessToken;
use WhiteMarket\Sdk\Auth\TokenProvider;
use WhiteMarket\Sdk\Auth\TokenStore;
use WhiteMarket\Sdk\Buying\BuyingApi;
use WhiteMarket\Sdk\Buying\BuyReconciliation;
use WhiteMarket\Sdk\Deals\DealsApi;
use WhiteMarket\Sdk\Export\ExportClient;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\GraphQL\GraphQLExecutor;
use WhiteMarket\Sdk\Inventory\InventoryApi;
use WhiteMarket\Sdk\Live\Live;
use WhiteMarket\Sdk\Live\LiveFactory;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Market\MarketApi;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Profile\ProfileApi;
use WhiteMarket\Sdk\Selling\SellingApi;
use WhiteMarket\Sdk\Store\StoreApi;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;
use WhiteMarket\Sdk\Transport\HttpClients;
use WhiteMarket\Sdk\Transport\HttpTransport;
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\Transport\SafeLogger;
use WhiteMarket\Sdk\Transport\TransportOptions;
use WhiteMarket\Sdk\Wallet\WalletApi;

/**
 * The entry point of the SDK (SDK_SPEC 2). Every client owns its transport and token: two clients with different
 * tokens in one process share nothing.
 */
final readonly class WhiteMarketClient
{
    public const PARTNER_TOKEN_ENV = 'WHITEMARKET_PARTNER_TOKEN';

    public const BASE_URL_ENV = 'WHITEMARKET_BASE_URL';

    private function __construct(
        public AuthApi $auth,
        public MarketApi $market,
        public BuyingApi $buying,
        public DealsApi $deals,
        public SellingApi $selling,
        public InventoryApi $inventory,
        public WalletApi $wallet,
        public ProfileApi $profile,
        public StoreApi $store,
        public ExportClient $exports,
        /**
         * The generated layer (SDK_SPEC 1.1): one typed method per operation plus `execute()` for raw documents, on
         * this client's token, retry policy and error mapping. Mutations are never retried here either; a purchase
         * made here has no customId reconciliation, use {@see BuyingApi} for that.
         */
        public GeneratedClient $generated,
        private LiveFactory $liveFactory,
    ) {}

    /**
     * A new live session (SDK_SPEC 3.11) with its own connection, authenticated like this client. With
     * `LiveTransport::Sse` an injected `$httpClient` of {@see create()} is used as is: it must return the body as a
     * stream and its read timeout becomes the read slice (see {@see LiveOptions}).
     */
    public function live(LiveOptions $options = new LiveOptions()): Live
    {
        return $this->liveFactory->create($options);
    }

    /**
     * Authenticates with exactly one of `$partnerToken` (preferred: the SDK exchanges it for access tokens and renews
     * them), `$accessToken` (a JWT the caller renews) or `$tokenProvider`.
     *
     * Without `$httpClient` the SDK uses Guzzle with the timeouts of `$transport` and a separate client for mutations
     * that never sends a request twice. An injected client is used as is for queries, mutations and exports: the SDK cannot set
     * its timeouts (PSR-18 has no API for them), and it must not resend a request whose connection failed, or a
     * mutation may run twice.
     *
     * @param BuyReconciliation $buyReconciliation how `buying` looks a purchase up after an ambiguous failure
     * @param int<1, max> $sellingBatchSize items per request of the `*Many` methods of `selling`, at least 1. No upper
     *                                      bound is enforced: the backend's maximum batch size is unconfirmed (P12 point 4);
     *                                      a value above it makes the server reject the chunk
     * @param ExportOptions $exportOptions settings of `$exports`, a standalone {@see ExportClient} (no authentication, SDK_SPEC 3.10) that shares
     *                                     `$httpClient`, `$requestFactory`, `$streamFactory`, `$retryPolicy` and `$logger` but not the GraphQL
     *                                     `$transport`: its own host, timeouts and size limit
     * @param CacheInterface|null $exportCache holds the `ETag` and `Last-Modified` of export files for conditional requests (never a body)
     *
     * @param TokenStore|null $tokenStore where the access token obtained with `$partnerToken` is kept; default in-memory.
     *                                    Share one (e.g. {@see \WhiteMarket\Sdk\Auth\Psr16TokenStore}) between workers
     *
     * @throws \InvalidArgumentException `$sellingBatchSize` below 1, not exactly one of the credentials, a `$partnerToken` that is not a valid HTTP
     *                                   header value (visible ASCII, no leading or trailing whitespace), `$tokenStore` without `$partnerToken`, or an
     *                                   `$accessToken` that is not a valid bearer token (RFC 6750 b64token, as every JWT is)
     */
    public static function create(
        #[\SensitiveParameter]
        ?string $partnerToken = null,
        #[\SensitiveParameter]
        ?string $accessToken = null,
        ?TokenProvider $tokenProvider = null,
        ?TokenStore $tokenStore = null,
        TransportOptions $transport = new TransportOptions(),
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        RetryPolicy $retryPolicy = new RetryPolicy(),
        LoggerInterface $logger = new NullLogger(),
        BuyReconciliation $buyReconciliation = new BuyReconciliation(),
        int $sellingBatchSize = SellingApi::DEFAULT_BATCH_SIZE,
        ExportOptions $exportOptions = new ExportOptions(),
        ?CacheInterface $exportCache = null,
    ): self {
        $logger = SafeLogger::wrap($logger);
        $messageFactory = GuzzleClientFactory::messageFactory();
        $http = new HttpTransport(
            self::httpClients($httpClient, $transport, $logger),
            $requestFactory ?? $messageFactory,
            $streamFactory ?? $messageFactory,
            $transport,
            $retryPolicy,
            $logger,
        );
        $provider = self::tokenProvider($http, $partnerToken, $accessToken, $tokenProvider, $tokenStore);
        $api = new GeneratedClient(new GraphQLExecutor($http, new BearerCredentials($provider)));

        $mapper = new ModelMapper();

        return new self(
            new AuthApi($provider, $api),
            new MarketApi($api, $mapper),
            new BuyingApi($api, $mapper, $buyReconciliation),
            new DealsApi($api, $mapper),
            new SellingApi($api, $mapper, $sellingBatchSize),
            new InventoryApi($api, $mapper),
            new WalletApi($api, $mapper),
            new ProfileApi($api, $mapper),
            new StoreApi($api, $mapper),
            ExportClient::create($httpClient, $requestFactory, $streamFactory, $exportOptions, $exportCache, $retryPolicy, $logger),
            $api,
            new LiveFactory($provider, $transport->baseUrl, $httpClient, $requestFactory ?? $messageFactory, $logger),
        );
    }

    /**
     * {@see create()} with the Partner Token from the `WHITEMARKET_PARTNER_TOKEN` environment variable and, when
     * `WHITEMARKET_BASE_URL` is set, that API origin in place of the one in `$transport`. Nothing else is read from the
     * environment (SDK_SPEC 2). Variables are read with `getenv()`: a dotenv loader must export them to the process.
     *
     * @param int<1, max> $sellingBatchSize items per request of the `*Many` methods of `selling`; no upper bound is
     *                                      enforced (see {@see create()})
     *
     * @throws \InvalidArgumentException `WHITEMARKET_PARTNER_TOKEN` is not set, empty or not a valid header value
     *                                   (a trailing newline is the usual cause), or an option is invalid
     */
    public static function fromEnv(
        ?TokenStore $tokenStore = null,
        TransportOptions $transport = new TransportOptions(),
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        RetryPolicy $retryPolicy = new RetryPolicy(),
        LoggerInterface $logger = new NullLogger(),
        BuyReconciliation $buyReconciliation = new BuyReconciliation(),
        int $sellingBatchSize = SellingApi::DEFAULT_BATCH_SIZE,
        ExportOptions $exportOptions = new ExportOptions(),
        ?CacheInterface $exportCache = null,
    ): self {
        $partnerToken = getenv(self::PARTNER_TOKEN_ENV);
        if (!\is_string($partnerToken) || $partnerToken === '') {
            throw new \InvalidArgumentException(self::PARTNER_TOKEN_ENV . ' is not set.');
        }
        if (!HeaderValue::isValid($partnerToken)) {
            throw new \InvalidArgumentException(self::PARTNER_TOKEN_ENV . ' is not a valid HTTP header value (visible ASCII, no leading or trailing whitespace): check for a trailing newline.');
        }
        $baseUrl = getenv(self::BASE_URL_ENV);
        if (\is_string($baseUrl) && $baseUrl !== '') {
            $transport = $transport->withBaseUrl($baseUrl);
        }

        return self::create(
            partnerToken: $partnerToken,
            tokenStore: $tokenStore,
            transport: $transport,
            httpClient: $httpClient,
            requestFactory: $requestFactory,
            streamFactory: $streamFactory,
            retryPolicy: $retryPolicy,
            logger: $logger,
            buyReconciliation: $buyReconciliation,
            sellingBatchSize: $sellingBatchSize,
            exportOptions: $exportOptions,
            exportCache: $exportCache,
        );
    }

    private static function tokenProvider(
        HttpTransport $http,
        #[\SensitiveParameter]
        ?string $partnerToken,
        #[\SensitiveParameter]
        ?string $accessToken,
        ?TokenProvider $tokenProvider,
        ?TokenStore $tokenStore,
    ): TokenProvider {
        if ($tokenStore instanceof TokenStore && $partnerToken === null) {
            throw new \InvalidArgumentException('tokenStore keeps the token obtained with partnerToken; pass it with partnerToken only.');
        }
        $providers = [];
        if ($partnerToken !== null) {
            if ($partnerToken !== '' && !HeaderValue::isValid($partnerToken)) { // '' is refused by Secret
                throw new \InvalidArgumentException('partnerToken is not a valid HTTP header value (visible ASCII, no leading or trailing whitespace).');
            }
            $providers[] = new PartnerTokenProvider($http, new Secret($partnerToken), $tokenStore ?? new InMemoryTokenStore());
        }
        if ($accessToken !== null) {
            if ($accessToken !== '' && !BearerToken::isValid($accessToken)) { // '' is refused by Secret
                throw new \InvalidArgumentException('accessToken is not a valid bearer token (RFC 6750 b64token).');
            }
            $providers[] = new StaticAccessToken(new Secret($accessToken));
        }
        if ($tokenProvider instanceof TokenProvider) {
            $providers[] = $tokenProvider;
        }
        if (\count($providers) !== 1) {
            throw new \InvalidArgumentException('Pass exactly one of partnerToken, accessToken or tokenProvider.');
        }

        return $providers[0];
    }

    private static function httpClients(?ClientInterface $httpClient, TransportOptions $transport, LoggerInterface $logger): HttpClients
    {
        if ($httpClient instanceof ClientInterface) {
            $logger->notice('WhiteMarket SDK: the injected HTTP client is used as is for queries, mutations and exports; the SDK timeouts are not applied to it.');
        }

        return HttpClients::from($httpClient, $transport);
    }
}
