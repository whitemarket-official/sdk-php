<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketNewMultiple\ProductMarketProduct;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketNewMultiple\ProductMarketProductHistory;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketNewMultiple\Result;
use WhiteMarket\Sdk\GraphQL\UnknownTypeValue;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Product;
use WhiteMarket\Sdk\Models\ProductHistory;
use WhiteMarket\Sdk\Tests\Support\SyntheticDeal;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(ModelMapper::class)]
#[CoversClass(ProductHistory::class)]
#[CoversClass(Product::class)]
final class ModelMapperBatchProductTest extends TestCase
{
    private function product(mixed $wire): Result
    {
        return Result::fromValue(SyntheticResult::of(Result::class, ['product' => $wire]), 'market_new_multiple[0]');
    }

    #[Test]
    public function aLiveListingMapsToAProduct(): void
    {
        $result = $this->product(SyntheticResult::typed('MarketProduct', ProductMarketProduct::class, ['id' => 'live-1', 'slug' => 'ak']));

        $mapped = (new ModelMapper())->batchProduct($result->product, 'market_new_multiple[0].product');

        self::assertInstanceOf(Product::class, $mapped);
        self::assertSame(['live-1', 'ak'], [$mapped->id, $mapped->slug]);
    }

    #[Test]
    public function anArchivedListingMapsToAProductHistory(): void
    {
        $result = $this->product(['__typename' => 'MarketProductHistory', 'id' => 'old-1'] + SyntheticDeal::productHistory('', [], [
            'product' => ProductMarketProductHistory::class,
            'params' => ProductMarketProductHistory::class . 'ParamsCSGOInventoryItem',
            'description' => ProductMarketProductHistory::class . 'DescriptionCSGOSteamItem',
        ]));

        $mapped = (new ModelMapper())->batchProduct($result->product, 'market_new_multiple[0].product');

        self::assertInstanceOf(ProductHistory::class, $mapped);
        self::assertSame('old-1', $mapped->id);
    }

    #[Test]
    public function aTypeTheServerAddedLaterAndAMissingProductMapToNull(): void
    {
        $mapper = new ModelMapper();
        $unknown = $this->product(['__typename' => 'SomethingNew', 'x' => 1]);

        self::assertInstanceOf(UnknownTypeValue::class, $unknown->product);
        self::assertNull($mapper->batchProduct($unknown->product, 'p'));
        self::assertNull($mapper->batchProduct($this->product(null)->product, 'p'));
        self::assertNull($mapper->batchProduct('a string', 'p'));
    }
}
