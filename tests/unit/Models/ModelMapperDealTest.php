<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketBuy;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketRemove;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketRemove\DescriptionCSGOSteamItem;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketRemove\ParamsCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketRemove\Result as RemoveResult;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\EdgesNode;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\EdgesNodeProduct;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\EdgesNodeProductDescriptionCSGOSteamItem;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistory\EdgesNodeProductParamsCSGOInventoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\DealHistoryItem;
use WhiteMarket\Sdk\Generated\Operation\Query\DealItem;
use WhiteMarket\Sdk\Generated\Operation\Query\DealItem\ItemCSGOInventoryItem as DealItemInventoryItem;
use WhiteMarket\Sdk\Models\Deal;
use WhiteMarket\Sdk\Models\DealCancelReason;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\InventoryItem;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\ProductHistory;
use WhiteMarket\Sdk\Models\ProductStatus;
use WhiteMarket\Sdk\Models\Sticker;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Support\SyntheticDeal;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(ModelMapper::class)]
#[CoversClass(InventoryItem::class)]
#[CoversClass(Sticker::class)]
#[CoversClass(ProductHistory::class)]
#[CoversClass(Deal::class)]
final class ModelMapperDealTest extends TestCase
{
    private const PATH = 'deal';

    private const DEAL_ID = SyntheticDeal::DEAL_ID;

    private const REMOVED_LISTING = [
        'product' => RemoveResult::class,
        'params' => ParamsCSGOInventoryItem::class,
        'description' => DescriptionCSGOSteamItem::class,
    ];

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): Deal}>
     */
    public static function dealsInProgress(): iterable
    {
        yield 'deal_item' => [self::dealItem(...)];
        yield 'market_buy' => [static fn(array $deal): Deal => (new ModelMapper())->deal(MarketBuy\Result::fromValue(
            SyntheticDeal::inProgress(MarketBuy::class, $deal),
            self::PATH,
        ), self::PATH)];
    }

    /**
     * @param \Closure(array<string, mixed>): Deal $map
     */
    #[Test]
    #[DataProvider('dealsInProgress')]
    public function mapsADealInProgress(\Closure $map): void
    {
        $deal = $map([]);

        self::assertSame(self::DEAL_ID, $deal->id);
        self::assertSame('synthetic-custom-id-7', $deal->customId);
        self::assertSame(DealStatus::Offered, $deal->status);
        self::assertSame('25.000 USD', (string) $deal->price);
        self::assertSame('2026-10-30T09:00:00+00:00', $deal->createdAt->format(\DATE_ATOM));
        self::assertSame(DeliveryType::Semi, $deal->deliveryType);
        self::assertSame('76561190000000002', $deal->steamId);
        self::assertSame('9000000000000000002', $deal->tradeId);
        self::assertFalse($deal->isTradeProtected);
        self::assertNull($deal->settlementTimeAt);
        self::assertSame('2026-10-30T21:00:00+00:00', $deal->expiresAt?->format(\DATE_ATOM));
        self::assertTrue($deal->isCancelByBuyerSupported);
        self::assertSame(1800, $deal->cancelByBuyerNeedWait);
        self::assertNull($deal->cancelReason);
        self::assertNull($deal->archivedAt);
        self::assertFalse($deal->isArchived());
        self::assertNull($deal->reversePenalty);
        self::assertNull($deal->reverseRefund);
        self::assertSame('40000000000000000002', $deal->item?->assetId);
        self::assertSame('4f1d9e2a-7b3c-4d5e-8f6a-1b2c3d4e5f60', $deal->product->id);
        self::assertSame('40000000000000000002', $deal->product->item?->assetId);
        self::assertSame('M4A4 | Howl', $deal->product->item->name, 'the product describes its item');
    }

    #[Test]
    public function aDealExchangedUnderTradeProtectionAwaitsSettlement(): void
    {
        $map = self::dealItem(...);

        $protected = $map(['status' => 'EXCHANGED', 'isTradeProtected' => true, 'settlementTimeAt' => '2026-11-06T09:00:00+00:00']);
        $unprotected = $map(['status' => 'EXCHANGED', 'isTradeProtected' => false]);
        $completed = $map(['status' => 'COMPLETED', 'isTradeProtected' => true]);

        self::assertTrue($protected->awaitingTradeProtection());
        self::assertSame('2026-11-06T09:00:00+00:00', $protected->settlementTimeAt?->format(\DATE_ATOM));
        self::assertFalse($unprotected->awaitingTradeProtection());
        self::assertFalse($completed->awaitingTradeProtection());
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function receiverTradeUrls(): iterable
    {
        yield 'set' => ['https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic', 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic'];
        yield 'null' => [null, null];
        yield 'empty' => ['', null];
    }

    /**
     * A MANUAL seller sends the item to `receiverTradeUrl` [docs 6-skins-sell]; the URL carries the trade token.
     */
    #[Test]
    #[DataProvider('receiverTradeUrls')]
    public function wrapsTheReceiverTradeUrlInASecretAndMapsEmptyToNull(?string $server, ?string $expected): void
    {
        $deal = self::dealItem(['receiverTradeUrl' => $server]);

        self::assertSame($expected, $deal->receiverTradeUrl?->reveal());
    }

    #[Test]
    public function keepsAnUnknownDealStatusAsItsRawValue(): void
    {
        $deal = self::dealItem(['status' => 'ESCROW']);

        self::assertInstanceOf(UnknownEnumValue::class, $deal->status);
        self::assertSame(DealStatus::class, $deal->status->enum);
        self::assertSame('ESCROW', $deal->status->value);
    }

    /**
     * @return iterable<string, array{\Closure(array<string, mixed>): Deal}>
     */
    public static function archivedDeals(): iterable
    {
        yield 'deal_history_item' => [self::dealHistoryItem(...)];
        yield 'deal_history node' => [static fn(array $deal): Deal => (new ModelMapper())->archivedDeal(EdgesNode::fromValue(
            SyntheticDeal::archived(DealHistory::class, $deal, [
                'result' => EdgesNode::class,
                'product' => EdgesNodeProduct::class,
                'params' => EdgesNodeProductParamsCSGOInventoryItem::class,
                'description' => EdgesNodeProductDescriptionCSGOSteamItem::class,
            ]),
            self::PATH,
        ), self::PATH)];
    }

    /**
     * @param \Closure(array<string, mixed>): Deal $map
     */
    #[Test]
    #[DataProvider('archivedDeals')]
    public function mapsAnArchivedDeal(\Closure $map): void
    {
        $deal = $map([]);

        self::assertSame(self::DEAL_ID, $deal->id);
        self::assertSame(DealStatus::SteamRollback, $deal->status);
        self::assertSame(DealCancelReason::SteamIssues, $deal->cancelReason);
        self::assertSame('2026-10-31T10:00:00+00:00', $deal->archivedAt?->format(\DATE_ATOM));
        self::assertTrue($deal->isArchived());
        self::assertSame('1.250 USD', (string) $deal->reversePenalty);
        self::assertSame('23.750 USD', (string) $deal->reverseRefund);
        self::assertNull($deal->tradeId);
        self::assertNull($deal->isTradeProtected);
        self::assertNull($deal->settlementTimeAt);
        self::assertNull($deal->expiresAt);
        self::assertFalse($deal->isCancelByBuyerSupported);
        self::assertNull($deal->cancelByBuyerNeedWait);
        self::assertNull($deal->receiverTradeUrl, 'the archive has no receiver trade URL');
        self::assertNull($deal->item, 'an archived deal carries its item on the product');
        self::assertSame('40000000000000000002', $deal->product->item?->assetId);
        self::assertSame('M4A4 | Howl', $deal->product->item->name);
        self::assertFalse($deal->awaitingTradeProtection());
    }

    #[Test]
    public function keepsAnUnknownCancelReasonAsItsRawValue(): void
    {
        $deal = self::dealHistoryItem(['cancelReason' => 'SOLAR_FLARE']);

        self::assertInstanceOf(UnknownEnumValue::class, $deal->cancelReason);
        self::assertSame('SOLAR_FLARE', $deal->cancelReason->value);
    }

    #[Test]
    public function anArchivedDealWithoutRollbackHasNoReverseAmounts(): void
    {
        $deal = self::dealHistoryItem(['reversePenaltyValue' => null, 'reverseRefundValue' => null, 'cancelReason' => null]);

        self::assertNull($deal->reversePenalty);
        self::assertNull($deal->reverseRefund);
        self::assertNull($deal->cancelReason);
    }

    #[Test]
    public function mapsARemovedListing(): void
    {
        $data = SyntheticDeal::productHistory(MarketRemove::class, [], self::REMOVED_LISTING);
        $data['status'] = 'DELETED';

        $product = (new ModelMapper())->productHistory(RemoveResult::fromValue($data, 'market_remove'), 'market_remove');

        self::assertSame('4f1d9e2a-7b3c-4d5e-8f6a-1b2c3d4e5f60', $product->id);
        self::assertSame(ProductStatus::Deleted, $product->status);
        self::assertSame('25.000 USD', (string) $product->price);
        self::assertSame('2026-10-31T10:00:00+00:00', $product->archivedAt->format(\DATE_ATOM));
        self::assertSame(DeliveryType::Manual, $product->deliveryType);
        self::assertSame('40000000000000000002', $product->assetId);
        self::assertSame('638240019', $product->classId);
        self::assertSame('188530139', $product->instanceId);
        self::assertSame('M4A4 | Howl', $product->item?->name);
        self::assertSame('0.0123', $product->item->float?->toString());
    }

    #[Test]
    public function aListingWithoutParamsHasNoItem(): void
    {
        $data = SyntheticDeal::productHistory(MarketRemove::class, [], self::REMOVED_LISTING);
        $data['params'] = null;

        self::assertNull((new ModelMapper())->productHistory(RemoveResult::fromValue($data, 'market_remove'), 'market_remove')->item);
    }

    /**
     * @return iterable<string, array{\Closure(): Deal, string}>
     */
    public static function malformedDecimals(): iterable
    {
        $bad = ['currency' => 'USD', 'value' => '1,5'];
        $product = SyntheticDeal::productHistory(DealItem::class);

        yield 'deal price' => [static fn(): Deal => self::dealItem(['price' => $bad]), 'deal.price.value'];
        yield 'deal item float' => [static fn(): Deal => self::dealItem(['item' => SyntheticResult::typed('CSGOInventoryItem', DealItemInventoryItem::class, ['float' => 'NaN'] + SyntheticDeal::item())]), 'deal.item.float'];
        yield 'product price' => [static fn(): Deal => self::dealItem(['product' => ['price' => $bad] + $product]), 'deal.product.price.value'];
        yield 'product item float' => [static fn(): Deal => self::dealItem([], ['float' => 'NaN']), 'deal.product.params.float'];
        yield 'archived price' => [static fn(): Deal => self::dealHistoryItem(['price' => $bad]), 'deal.price.value'];
        yield 'archived reverse penalty' => [static fn(): Deal => self::dealHistoryItem(['reversePenaltyValue' => $bad]), 'deal.reversePenaltyValue.value'];
        yield 'archived reverse refund' => [static fn(): Deal => self::dealHistoryItem(['reverseRefundValue' => $bad]), 'deal.reverseRefundValue.value'];
    }

    /**
     * @param \Closure(): Deal $map
     */
    #[Test]
    #[DataProvider('malformedDecimals')]
    public function rejectsAMalformedDecimalWithItsPath(\Closure $map, string $path): void
    {
        $this->expectExceptionObject(new DecodeException($path, 'not a decimal number'));

        $map();
    }

    /**
     * @param array<string, mixed> $deal overrides of the deal
     * @param array<string, mixed> $params overrides of the product's item
     */
    private static function dealItem(array $deal, array $params = []): Deal
    {
        $data = SyntheticDeal::inProgress(DealItem::class, $deal, $params);

        return (new ModelMapper())->deal(DealItem\Result::fromValue($data, self::PATH), self::PATH);
    }

    /**
     * @param array<string, mixed> $deal overrides of the deal
     */
    private static function dealHistoryItem(array $deal): Deal
    {
        $data = SyntheticDeal::archived(DealHistoryItem::class, $deal);

        return (new ModelMapper())->archivedDeal(DealHistoryItem\Result::fromValue($data, self::PATH), self::PATH);
    }
}
