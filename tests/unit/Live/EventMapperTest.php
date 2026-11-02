<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Live\Event\DealCancelled;
use WhiteMarket\Sdk\Live\Event\DealCompleted;
use WhiteMarket\Sdk\Live\Event\DealEvent;
use WhiteMarket\Sdk\Live\Event\DealExchanged;
use WhiteMarket\Sdk\Live\Event\DealExpired;
use WhiteMarket\Sdk\Live\Event\DealHold;
use WhiteMarket\Sdk\Live\Event\DealInitiated;
use WhiteMarket\Sdk\Live\Event\DealNeedConfirmation;
use WhiteMarket\Sdk\Live\Event\DealOffered;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\Event\ProductEdited;
use WhiteMarket\Sdk\Live\Event\ProductRemoved;
use WhiteMarket\Sdk\Live\Event\UnknownEvent;
use WhiteMarket\Sdk\Live\EventMapper;
use WhiteMarket\Sdk\Live\MappedEvent;
use WhiteMarket\Sdk\Live\Protocol\MessageDecoder;
use WhiteMarket\Sdk\Live\Protocol\Publication;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Support\LiveFixture;

#[CoversClass(EventMapper::class)]
#[CoversClass(MappedEvent::class)]
#[CoversClass(ProductAdded::class)]
#[CoversClass(ProductEdited::class)]
#[CoversClass(ProductRemoved::class)]
#[CoversClass(DealEvent::class)]
#[CoversClass(UnknownEvent::class)]
final class EventMapperTest extends TestCase
{
    private const PERSONAL = '#42';

    #[Test]
    public function mapsAProductAddedMessageFromTheEnvelopeToTheEvent(): void
    {
        $publication = MessageDecoder::decode(LiveFixture::raw('sse_product_added.json'));
        self::assertInstanceOf(Publication::class, $publication);

        $mapped = EventMapper::map($publication);

        self::assertNull($mapped->error);
        $event = $mapped->event;
        self::assertInstanceOf(ProductAdded::class, $event);
        self::assertSame('market_products_updates', $event->channel);
        self::assertSame('11111111-1111-4111-8111-111111111111', $event->id);
        self::assertSame(['730', '30000000000000001', '5000000001', '0'], [$event->appId, $event->assetId, $event->classId, $event->instanceId]);
        self::assertSame('2.100', $event->price->amount->toString());
        self::assertSame(Currency::Usd, $event->price->currency);
        self::assertSame('Synthetic Item | Test (Field-Tested)', $event->marketHashName);
        self::assertSame('steam://run/730//+csgo_econ_action_preview%20SYNTHETICINSPECT1', $event->inspectLink);
        self::assertSame(DeliveryType::Semi, $event->deliveryType);
        self::assertSame(DeliveryPromise::Minutes10, $event->deliveryPromise);
        self::assertSame('0.152345', $event->float?->toString());
        self::assertSame(['282', '661'], [$event->paintIndex, $event->paintSeed]);
        self::assertSame([['slot' => 0, 'name' => 'Synthetic Sticker | Test', 'wear' => '0.1']], $event->stickers);
        self::assertSame([['slot' => 0, 'name' => 'Synthetic Charm | Test', 'pattern' => '42']], $event->keychains);
        self::assertSame([], $event->extra);
    }

    #[Test]
    public function mapsTheSameEventWhenTheMessageIsAnObject(): void
    {
        $fromString = MessageDecoder::decode(LiveFixture::raw('ws_product_added.json'));
        $fromObject = MessageDecoder::decode(LiveFixture::raw('ws_product_added_object_message.json'));
        self::assertInstanceOf(Publication::class, $fromString);
        self::assertInstanceOf(Publication::class, $fromObject);

        $this->assertValueEquals(EventMapper::map($fromString), EventMapper::map($fromObject));
        self::assertInstanceOf(ProductAdded::class, EventMapper::map($fromObject)->event);
    }

    #[Test]
    public function keepsUnmappedKeysInExtra(): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::with('product_added.json', ['new_field' => ['a' => 1], 'rarity' => 'rare'])));

        self::assertInstanceOf(ProductAdded::class, $mapped->event);
        self::assertSame(['new_field' => ['a' => 1], 'rarity' => 'rare'], $mapped->event->extra);
    }

    #[Test]
    public function keepsIdsBeyondTwoToThe53AsExactStrings(): void
    {
        $ids = ['asset_id' => '18446744073709551615', 'class_id' => '9223372036854775808', 'instance_id' => '9007199254740993'];
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::with('product_added.json', $ids)));

        self::assertInstanceOf(ProductAdded::class, $mapped->event);
        self::assertSame(['18446744073709551615', '9223372036854775808', '9007199254740993'], [$mapped->event->assetId, $mapped->event->classId, $mapped->event->instanceId]);
    }

    #[Test]
    public function mapsAProductAddedWithBlankOptionalsAndNoListsToNulls(): void
    {
        $blank = ['inspect_url' => '', 'float' => '', 'paint_index' => '', 'paint_seed' => ' ', 'delivery_type' => '', 'delivery_promise' => ''];
        $message = LiveFixture::without(LiveFixture::with('product_added.json', $blank), ['stickers', 'keychains']);

        $event = EventMapper::map(LiveFixture::publication($message))->event;

        self::assertInstanceOf(ProductAdded::class, $event);
        self::assertSame([null, null, null, null, null, null], [$event->inspectLink, $event->float, $event->paintIndex, $event->paintSeed, $event->deliveryType, $event->deliveryPromise]);
        self::assertSame([[], []], [$event->stickers, $event->keychains]);
    }

    #[Test]
    public function mapsAProductAddedWithMissingOptionalKeysToNulls(): void
    {
        $message = LiveFixture::without(LiveFixture::message('product_added.json'), ['inspect_url', 'float', 'paint_index', 'paint_seed', 'delivery_type', 'delivery_promise']);

        $mapped = EventMapper::map(LiveFixture::publication($message));

        self::assertNull($mapped->error);
        self::assertInstanceOf(ProductAdded::class, $mapped->event);
        self::assertNull($mapped->event->deliveryType);
    }

    /**
     * @return iterable<string, array{string, string, DeliveryType|DeliveryPromise|UnknownEnumValue}>
     */
    public static function deliveryValues(): iterable
    {
        yield 'type auto' => ['delivery_type', 'auto', DeliveryType::Auto];
        yield 'type semi' => ['delivery_type', 'semi', DeliveryType::Semi];
        yield 'type manual' => ['delivery_type', 'manual', DeliveryType::Manual];
        yield 'type upper case' => ['delivery_type', 'SEMI', DeliveryType::Semi];
        yield 'type unknown keeps the upper-cased wire value' => ['delivery_type', 'drone', new UnknownEnumValue(DeliveryType::class, 'DRONE')];
        yield 'promise 10min' => ['delivery_promise', '10min', DeliveryPromise::Minutes10];
        yield 'promise 1hour' => ['delivery_promise', '1hour', DeliveryPromise::Hour1];
        yield 'promise 12hours' => ['delivery_promise', '12hours', DeliveryPromise::Hours12];
        yield 'promise 24hours' => ['delivery_promise', '24hours', DeliveryPromise::Hours24];
        yield 'promise unknown keeps the wire value' => ['delivery_promise', '3days', new UnknownEnumValue(DeliveryPromise::class, '3days')];
        yield 'promise enum name is not the wire value' => ['delivery_promise', 'MINUTES10', new UnknownEnumValue(DeliveryPromise::class, 'MINUTES10')];
    }

    #[Test]
    #[DataProvider('deliveryValues')]
    public function mapsDeliveryValuesThroughTheSharedWireRules(string $key, string $value, DeliveryType|DeliveryPromise|UnknownEnumValue $expected): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::with('product_added.json', [$key => $value])));

        self::assertNull($mapped->error);
        self::assertInstanceOf(ProductAdded::class, $mapped->event);
        $this->assertValueEquals($expected, $key === 'delivery_type' ? $mapped->event->deliveryType : $mapped->event->deliveryPromise);
    }

    #[Test]
    public function mapsAPartialProductEdited(): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::message('product_edited_partial.json')));

        self::assertNull($mapped->error);
        $event = $mapped->event;
        self::assertInstanceOf(ProductEdited::class, $event);
        self::assertSame('11111111-1111-4111-8111-111111111111', $event->id);
        self::assertSame('2.10', $event->price?->amount->toString());
        self::assertSame(Currency::Usd, $event->price->currency);
        self::assertSame(
            [null, null, null, null, null, null, null, null, null, null, null, null, null, []],
            [$event->appId, $event->assetId, $event->classId, $event->instanceId, $event->marketHashName, $event->inspectLink, $event->deliveryType, $event->deliveryPromise, $event->float, $event->paintIndex, $event->paintSeed, $event->stickers, $event->keychains, $event->extra],
        );
    }

    #[Test]
    public function mapsAFullProductEdited(): void
    {
        $event = EventMapper::map(LiveFixture::publication(LiveFixture::message('product_edited.json')))->event;

        self::assertInstanceOf(ProductEdited::class, $event);
        self::assertSame('2.050', $event->price?->amount->toString());
        self::assertSame(DeliveryPromise::Hour1, $event->deliveryPromise);
        self::assertSame(['730', '30000000000000001', '5000000001', '0'], [$event->appId, $event->assetId, $event->classId, $event->instanceId]);
        self::assertCount(1, $event->stickers ?? []);
    }

    #[Test]
    public function aBlankPriceInAnEditIsNotAChange(): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::with('product_edited_partial.json', ['price' => ''])));

        self::assertNull($mapped->error);
        self::assertInstanceOf(ProductEdited::class, $mapped->event);
        self::assertNull($mapped->event->price);
    }

    #[Test]
    public function mapsAProductRemoved(): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::message('product_removed.json')));

        self::assertNull($mapped->error);
        $event = $mapped->event;
        self::assertInstanceOf(ProductRemoved::class, $event);
        self::assertSame('11111111-1111-4111-8111-111111111111', $event->id);
        self::assertSame(['730', '30000000000000001', '5000000001', '0'], [$event->appId, $event->assetId, $event->classId, $event->instanceId]);
        self::assertSame('2.100', $event->price->amount->toString());
        self::assertSame(Currency::Usd, $event->price->currency);
        self::assertSame('Synthetic Item | Test (Field-Tested)', $event->marketHashName);
    }

    /**
     * @return iterable<string, array{string, class-string<DealEvent>}>
     */
    public static function dealTypes(): iterable
    {
        yield 'initiated' => ['market_deal_initiated', DealInitiated::class];
        yield 'offered' => ['market_deal_offered', DealOffered::class];
        yield 'need_confirmation' => ['market_deal_need_confirmation', DealNeedConfirmation::class];
        yield 'exchanged' => ['market_deal_exchanged', DealExchanged::class];
        yield 'completed' => ['market_deal_completed', DealCompleted::class];
        yield 'expired' => ['market_deal_expired', DealExpired::class];
        yield 'cancelled' => ['market_deal_cancelled', DealCancelled::class];
        yield 'hold' => ['market_deal_hold', DealHold::class];
    }

    /**
     * @param class-string<DealEvent> $class
     */
    #[Test]
    #[DataProvider('dealTypes')]
    public function mapsEachDealTypeToItsClass(string $type, string $class): void
    {
        $message = ['type' => $type] + LiveFixture::message('deal_completed.json');

        $mapped = EventMapper::map(LiveFixture::publication($message, self::PERSONAL));

        self::assertNull($mapped->error);
        self::assertInstanceOf($class, $mapped->event);
        self::assertSame(self::PERSONAL, $mapped->event->channel);
        self::assertSame('22222222-2222-4222-8222-222222222222', $mapped->event->dealId);
        self::assertSame(DealAction::Buy, $mapped->event->action);
        self::assertSame('Synthetic Item | Test (Field-Tested)', $mapped->event->productName);
        self::assertSame('90000000000000001', $mapped->event->steamId);
        self::assertSame([], $mapped->event->extra);
    }

    /**
     * @return iterable<string, array{string, DealAction|UnknownEnumValue}>
     */
    public static function dealActions(): iterable
    {
        yield 'buy' => ['buy', DealAction::Buy];
        yield 'sell' => ['sell', DealAction::Sell];
        yield 'upper case' => ['SELL', DealAction::Sell];
        yield 'unknown' => ['swap', new UnknownEnumValue(DealAction::class, 'SWAP')];
    }

    #[Test]
    #[DataProvider('dealActions')]
    public function mapsTheDealAction(string $wire, DealAction|UnknownEnumValue $expected): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::with('deal_completed.json', ['deal_action_type' => $wire]), self::PERSONAL));

        self::assertInstanceOf(DealCompleted::class, $mapped->event);
        $this->assertValueEquals($expected, $mapped->event->action);
    }

    #[Test]
    public function aDealWithoutOptionalFieldsHasNullsAndKeepsExtra(): void
    {
        $message = LiveFixture::without(LiveFixture::with('deal_completed.json', ['market_product_name' => '', 'extra_key' => 7]), ['deal_steam_id']);

        $mapped = EventMapper::map(LiveFixture::publication($message, self::PERSONAL));

        self::assertNull($mapped->error);
        self::assertInstanceOf(DealCompleted::class, $mapped->event);
        self::assertNull($mapped->event->productName);
        self::assertNull($mapped->event->steamId);
        self::assertSame(['extra_key' => 7], $mapped->event->extra);
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>, string}>
     */
    public static function malformedContent(): iterable
    {
        $added = 'product_added.json';
        foreach (['id', 'app_id', 'asset_id', 'class_id', 'instance_id', 'price', 'name_hash'] as $key) {
            yield "added: $key missing" => [$added, [], [$key], 'live.market_product_added.' . $key];
            yield "added: $key blank" => [$added, [$key => ' '], [], 'live.market_product_added.' . $key];
            yield "added: $key a number" => [$added, [$key => 5], [], 'live.market_product_added.' . $key];
            yield "added: $key null" => [$added, [$key => null], [], 'live.market_product_added.' . $key];
        }
        foreach (['app_id', 'asset_id', 'class_id', 'instance_id'] as $key) {
            yield "added: $key not digits" => [$added, [$key => '12ab'], [], 'live.market_product_added.' . $key];
            yield "added: $key negative" => [$added, [$key => '-5'], [], 'live.market_product_added.' . $key];
        }
        yield 'added: price with an exponent' => [$added, ['price' => '2e1'], [], 'live.market_product_added.price'];
        yield 'added: price not a number' => [$added, ['price' => 'free'], [], 'live.market_product_added.price'];
        yield 'added: float not a number' => [$added, ['float' => 'NaN'], [], 'live.market_product_added.float'];
        yield 'added: float a JSON number' => [$added, ['float' => 0.15], [], 'live.market_product_added.float'];
        yield 'added: inspect_url a number' => [$added, ['inspect_url' => 1], [], 'live.market_product_added.inspect_url'];
        yield 'added: delivery_type an object' => [$added, ['delivery_type' => ['x']], [], 'live.market_product_added.delivery_type'];
        yield 'added: paint_seed a number' => [$added, ['paint_seed' => 661], [], 'live.market_product_added.paint_seed'];
        yield 'added: stickers an object' => [$added, ['stickers' => ['a' => 1]], [], 'live.market_product_added.stickers'];
        yield 'added: stickers a string' => [$added, ['stickers' => 'none'], [], 'live.market_product_added.stickers'];
        yield 'added: a sticker a string' => [$added, ['stickers' => ['x']], [], 'live.market_product_added.stickers[0]'];
        yield 'added: a keychain a list' => [$added, ['keychains' => [[1]]], [], 'live.market_product_added.keychains[0]'];

        $edited = 'product_edited_partial.json';
        yield 'edited: id missing' => [$edited, [], ['id'], 'live.market_product_edited.id'];
        yield 'edited: id blank' => [$edited, ['id' => ''], [], 'live.market_product_edited.id'];
        yield 'edited: id a number' => [$edited, ['id' => 7], [], 'live.market_product_edited.id'];
        yield 'edited: price not a number' => [$edited, ['price' => 'x'], [], 'live.market_product_edited.price'];
        yield 'edited: price a number' => [$edited, ['price' => 2.1], [], 'live.market_product_edited.price'];
        yield 'edited: asset_id not digits' => [$edited, ['asset_id' => 'abc'], [], 'live.market_product_edited.asset_id'];
        yield 'edited: stickers not a list' => [$edited, ['stickers' => 'x'], [], 'live.market_product_edited.stickers'];

        $removed = 'product_removed.json';
        foreach (['id', 'app_id', 'asset_id', 'class_id', 'instance_id', 'price', 'name_hash'] as $key) {
            yield "removed: $key missing" => [$removed, [], [$key], 'live.market_product_removed.' . $key];
        }
        yield 'removed: price not a number' => [$removed, ['price' => '1,5'], [], 'live.market_product_removed.price'];
        yield 'removed: class_id not digits' => [$removed, ['class_id' => '1.5'], [], 'live.market_product_removed.class_id'];

        $deal = 'deal_completed.json';
        foreach (['deal_id', 'deal_action_type'] as $key) {
            yield "deal: $key missing" => [$deal, [], [$key], 'live.market_deal_completed.' . $key];
            yield "deal: $key blank" => [$deal, [$key => ''], [], 'live.market_deal_completed.' . $key];
            yield "deal: $key a number" => [$deal, [$key => 5], [], 'live.market_deal_completed.' . $key];
        }
        yield 'deal: product name a number' => [$deal, ['market_product_name' => 5], [], 'live.market_deal_completed.market_product_name'];
        yield 'deal: steam id a number' => [$deal, ['deal_steam_id' => 90000000000000001], [], 'live.market_deal_completed.deal_steam_id'];
    }

    /**
     * @param array<string, mixed> $override
     * @param list<string> $drop
     */
    #[Test]
    #[DataProvider('malformedContent')]
    public function aMalformedKnownMessageBecomesAnUnknownEventWithTheDecodeError(string $fixture, array $override, array $drop, string $path): void
    {
        $message = LiveFixture::without(LiveFixture::with($fixture, $override), $drop);

        $mapped = EventMapper::map(LiveFixture::publication($message));

        self::assertInstanceOf(DecodeException::class, $mapped->error);
        self::assertSame($path, $mapped->error->path);
        self::assertInstanceOf(UnknownEvent::class, $mapped->event);
        self::assertSame($message['type'], $mapped->event->type);
        self::assertSame($message['content'], $mapped->event->rawContent);
        self::assertSame('market_products_updates', $mapped->event->channel);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function badContent(): iterable
    {
        yield 'content missing' => [null];
        yield 'content a string' => ['text'];
        yield 'content a list' => [[1, 2]];
        yield 'content a number' => [5];
    }

    #[Test]
    #[DataProvider('badContent')]
    public function aKnownTypeWithoutAContentObjectIsMalformed(mixed $content): void
    {
        $message = ['type' => 'market_product_removed'] + ($content === null ? [] : ['content' => $content]);

        $mapped = EventMapper::map(LiveFixture::publication($message));

        self::assertInstanceOf(DecodeException::class, $mapped->error);
        self::assertSame('live.market_product_removed', $mapped->error->path);
        self::assertInstanceOf(UnknownEvent::class, $mapped->event);
    }

    #[Test]
    public function aMalformedMessageErrorNeverHoldsServerValues(): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::with('product_added.json', ['price' => 'SERVER-PRICE-MARK'])));

        self::assertInstanceOf(DecodeException::class, $mapped->error);
        self::assertStringNotContainsString('MARK', $mapped->error->getMessage());
    }

    #[Test]
    public function anUnknownTypeBecomesAnUnknownEventWithoutAnError(): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::message('wallet_balance_updated.json'), self::PERSONAL));

        self::assertNull($mapped->error);
        $this->assertValueEquals(new UnknownEvent(self::PERSONAL, 'wallet_balance_updated', ['balance' => '10.00', 'currency' => 'USD']), $mapped->event);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, string|null, array<array-key, mixed>}>
     */
    public static function unknownMessages(): iterable
    {
        yield 'type without content keeps the whole message' => [['type' => 'x_new'], 'x_new', ['type' => 'x_new']];
        yield 'content a string' => [['type' => 'x_new', 'content' => 'hi'], 'x_new', ['type' => 'x_new', 'content' => 'hi']];
        yield 'no type' => [['content' => ['a' => 1]], null, ['a' => 1]];
        yield 'type a number' => [['type' => 5, 'content' => ['a' => 1]], null, ['a' => 1]];
        yield 'empty object' => [[], null, []];
    }

    /**
     * @param array<array-key, mixed> $message
     * @param array<array-key, mixed> $rawContent
     */
    #[Test]
    #[DataProvider('unknownMessages')]
    public function shapesWithoutAKnownTypeAreUnknownEvents(array $message, ?string $type, array $rawContent): void
    {
        $mapped = EventMapper::map(LiveFixture::publication($message, 'broadcast'));

        self::assertNull($mapped->error);
        $this->assertValueEquals(new UnknownEvent('broadcast', $type, $rawContent), $mapped->event);
    }

    #[Test]
    public function aKnownTypeOnTheBroadcastChannelIsStillMappedByType(): void
    {
        $mapped = EventMapper::map(LiveFixture::publication(LiveFixture::message('product_removed.json'), 'broadcast'));

        self::assertInstanceOf(ProductRemoved::class, $mapped->event);
        self::assertSame('broadcast', $mapped->event->channel);
    }

    /**
     * @return iterable<string, array{array<array-key, mixed>, array<array-key, mixed>|string}>
     */
    public static function nonMessagePublications(): iterable
    {
        yield 'plain text message' => [['message' => 'maintenance at noon'], 'maintenance at noon'];
        yield 'truncated JSON message' => [['message' => '{"type":"market_product_added"'], '{"type":"market_product_added"'];
        yield 'JSON scalar message' => [['message' => '5'], '5'];
        yield 'JSON null message' => [['message' => 'null'], 'null'];
        yield 'no message key' => [['text' => 'hi'], ['text' => 'hi']];
        yield 'message a number' => [['message' => 5], ['message' => 5]];
        yield 'message a list' => [['message' => [1, 2]], ['message' => [1, 2]]];
    }

    /**
     * @param array<array-key, mixed> $data
     * @param array<array-key, mixed>|string $rawContent
     */
    #[Test]
    #[DataProvider('nonMessagePublications')]
    public function aPublicationWithoutAMessageObjectIsAnUnknownEvent(array $data, array|string $rawContent): void
    {
        $mapped = EventMapper::map(new Publication('broadcast', $data));

        self::assertNull($mapped->error);
        $this->assertValueEquals(new UnknownEvent('broadcast', null, $rawContent), $mapped->event);
    }

    #[Test]
    public function aMessageNestedBeyondDepth64IsAnUnknownEventWithTheRawString(): void
    {
        $deep = '{"type":"x","content":' . str_repeat('[', 70) . str_repeat(']', 70) . '}';

        $mapped = EventMapper::map(new Publication('market_products_updates', ['message' => $deep]));

        $this->assertValueEquals(new UnknownEvent('market_products_updates', null, $deep), $mapped->event);
    }

    /**
     * Value equality for readonly objects (`assertSame` would compare identities; php-cs-fixer rewrites `assertEquals`).
     */
    private function assertValueEquals(mixed $expected, mixed $actual): void
    {
        self::assertThat($actual, self::equalTo($expected));
    }
}
