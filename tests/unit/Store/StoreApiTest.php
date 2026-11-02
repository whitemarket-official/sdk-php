<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Operation\Mutation\StoreEdit\Result as EditResult;
use WhiteMarket\Sdk\Generated\Operation\Query\StoreMy\Result as MyResult;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\StoreAvatar;
use WhiteMarket\Sdk\Models\StoreBanner;
use WhiteMarket\Sdk\Store\StoreApi;
use WhiteMarket\Sdk\Store\StoreSettings;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;
use WhiteMarket\Sdk\Tests\Support\TestTransport;
use WhiteMarket\Sdk\Transport\RetryPolicy;

#[CoversClass(StoreApi::class)]
final class StoreApiTest extends TestCase
{
    private function api(FakeHttpClient $http, RetryPolicy $retry = new RetryPolicy()): StoreApi
    {
        return new StoreApi(TestTransport::generatedClient($http, $retry), new ModelMapper());
    }

    /**
     * @param array<string, mixed> $data
     */
    private function ok(array $data): ResponseInterface
    {
        return FakeHttpClient::json(['data' => $data]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function store(array $overrides = []): array
    {
        return SyntheticResult::of(MyResult::class, ['id' => 'store-1', 'storeName' => 'Synthetic Shop', 'avatar' => 'AVATAR_2'] + $overrides);
    }

    #[Test]
    public function getSendsStoreMyAndMapsTheStore(): void
    {
        $http = new FakeHttpClient($this->ok(['store_my' => $this->store(['banner' => 'BANNER_4'])]));

        $store = $this->api($http)->get();

        self::assertSame([['StoreMy', []]], $http->operations());
        self::assertSame('store-1', $store->id);
        self::assertSame('Synthetic Shop', $store->storeName);
        self::assertSame(StoreAvatar::Avatar2, $store->avatar);
        self::assertSame(StoreBanner::Banner4, $store->banner);
    }

    #[Test]
    public function getIsRetriedLikeAQuery(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(503), $this->ok(['store_my' => $this->store()]));

        $store = $this->api($http, new RetryPolicy(maxAttempts: 2))->get();

        self::assertSame('store-1', $store->id);
        self::assertCount(2, $http->requests());
    }

    #[Test]
    public function editSendsStoreEditWithTheEncodedSettingsAndMapsTheResult(): void
    {
        $http = new FakeHttpClient($this->ok(['store_edit' => SyntheticResult::of(EditResult::class, ['id' => 'store-2', 'storeName' => 'Shop'])]));

        $store = $this->api($http)->edit(new StoreSettings(storeName: 'Shop', isFavoritesPublic: false));

        self::assertSame([['StoreEdit', ['storeInput' => ['isFavoritesPublic' => false, 'storeName' => 'Shop']]]], $http->operations());
        self::assertSame('store-2', $store->id);
        self::assertSame('Shop', $store->storeName);
    }

    #[Test]
    public function editIsSentOnceAndNeverRetriedOn503(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(503), $this->ok(['store_edit' => SyntheticResult::of(EditResult::class)]));

        try {
            $this->api($http, new RetryPolicy(maxAttempts: 3))->edit(new StoreSettings(storeName: 'Shop'));
            self::fail('The 503 must propagate');
        } catch (WhiteMarketException) {
            self::assertCount(1, $http->requests());
        }
    }
}
