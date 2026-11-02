<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

use WhiteMarket\Sdk\Auth\BearerCredentials;
use WhiteMarket\Sdk\Auth\InMemoryTokenStore;
use WhiteMarket\Sdk\Auth\PartnerTokenProvider;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\TokenStore;
use WhiteMarket\Sdk\Export\ExportKind;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\GraphQL\GraphQLExecutor;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\Event\ProductEdited;
use WhiteMarket\Sdk\Live\Event\ProductRemoved;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveTransport;
use WhiteMarket\Sdk\Transport\GuzzleClientFactory;
use WhiteMarket\Sdk\Transport\HttpClients;
use WhiteMarket\Sdk\Transport\HttpTransport;
use WhiteMarket\Sdk\Transport\TransportOptions;
use WhiteMarket\Sdk\WhiteMarketClient;

/**
 * The live smoke of SDK_SPEC 6.4: read-only queries, one export file, SSE and WebSocket. The only mutation that can
 * happen is the SDK's own `auth_token` exchange inside the token provider.
 */
final class Smoke
{
    public const EXPORT_ROWS = 100;

    public const LIVE_TIMEOUT_SECONDS = 120.0;

    /**
     * @return list<StepResult>
     */
    public static function run(#[\SensitiveParameter] string $partnerToken): array
    {
        $store = new InMemoryTokenStore();
        $options = self::transportOptions();
        $api = self::readOnlyGeneratedClient($partnerToken, $options, $store);
        $client = WhiteMarketClient::create(partnerToken: $partnerToken, tokenStore: $store, transport: $options);

        $results = [];
        foreach (QueryCatalog::steps($api) as $name => $call) {
            $results[] = Runner::run('query ' . $name, static function () use ($call): ?string {
                $call();

                return null;
            });
        }
        $results[] = Runner::run('export products meta', static function () use ($client): ?string {
            $client->exports->meta(ExportKind::Products);

            return null;
        });
        $results[] = Runner::run('export products first ' . self::EXPORT_ROWS . ' rows', static fn(): ?string => self::exportRows($client));
        foreach ([LiveTransport::WebSocket, LiveTransport::Sse] as $transport) {
            $results[] = Runner::run('live ' . $transport->value . ' products update', static fn(): ?string => self::liveEvent($client, $transport));
        }

        return $results;
    }

    private static function transportOptions(): TransportOptions
    {
        $options = new TransportOptions();
        $baseUrl = getenv(WhiteMarketClient::BASE_URL_ENV);

        return \is_string($baseUrl) && $baseUrl !== '' ? $options->withBaseUrl($baseUrl) : $options;
    }

    /**
     * A generated client whose executor refuses every mutation ({@see ReadOnlyExecutor}).
     */
    private static function readOnlyGeneratedClient(#[\SensitiveParameter] string $partnerToken, TransportOptions $options, TokenStore $store): GeneratedClient
    {
        $factory = GuzzleClientFactory::messageFactory();
        $http = new HttpTransport(HttpClients::from(null, $options), $factory, $factory, $options);
        $provider = new PartnerTokenProvider($http, new Secret($partnerToken), $store);

        return new GeneratedClient(new ReadOnlyExecutor(new GraphQLExecutor($http, new BearerCredentials($provider))));
    }

    private static function exportRows(WhiteMarketClient $client): ?string
    {
        $rows = $client->exports->streamProducts();
        $count = 0;
        foreach ($rows as $_) {
            if (++$count >= self::EXPORT_ROWS) {
                break;
            }
        }
        unset($rows); // releases the body stream

        return $count === self::EXPORT_ROWS ? null : 'fewer rows than expected';
    }

    private static function liveEvent(WhiteMarketClient $client, LiveTransport $transport): ?string
    {
        $live = $client->live(new LiveOptions(transport: $transport));
        try {
            $live->subscribeProducts();
            $deadline = microtime(true) + self::LIVE_TIMEOUT_SECONDS;
            while (($left = $deadline - microtime(true)) > 0) {
                $event = $live->poll(min(5.0, $left));
                if ($event instanceof ProductAdded || $event instanceof ProductEdited || $event instanceof ProductRemoved) {
                    return null;
                }
            }
        } finally {
            $live->close();
        }

        return 'no products update within ' . (int) self::LIVE_TIMEOUT_SECONDS . ' s';
    }
}
