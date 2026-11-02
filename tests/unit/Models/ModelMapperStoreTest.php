<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Generated\Operation\Mutation\StoreEdit\Achievements as EditAchievements;
use WhiteMarket\Sdk\Generated\Operation\Mutation\StoreEdit\AchievementsParamsAchievementBooleanParam as EditBooleanParam;
use WhiteMarket\Sdk\Generated\Operation\Mutation\StoreEdit\AchievementsParamsAchievementStringParam as EditStringParam;
use WhiteMarket\Sdk\Generated\Operation\Mutation\StoreEdit\Result as EditResult;
use WhiteMarket\Sdk\Generated\Operation\Query\StoreMy\Achievements as MyAchievements;
use WhiteMarket\Sdk\Generated\Operation\Query\StoreMy\AchievementsParamsAchievementBooleanParam as MyBooleanParam;
use WhiteMarket\Sdk\Generated\Operation\Query\StoreMy\AchievementsParamsAchievementStringParam as MyStringParam;
use WhiteMarket\Sdk\Generated\Operation\Query\StoreMy\Result as MyResult;
use WhiteMarket\Sdk\Models\AchievementCondition;
use WhiteMarket\Sdk\Models\AchievementKind;
use WhiteMarket\Sdk\Models\AchievementName;
use WhiteMarket\Sdk\Models\AchievementStatus;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\StoreAvatar;
use WhiteMarket\Sdk\Models\StoreBanner;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Store\Achievement;
use WhiteMarket\Sdk\Store\AchievementParam;
use WhiteMarket\Sdk\Store\Store;
use WhiteMarket\Sdk\Tests\Support\SyntheticResult;

#[CoversClass(ModelMapper::class)]
#[CoversClass(Store::class)]
#[CoversClass(Achievement::class)]
#[CoversClass(AchievementParam::class)]
final class ModelMapperStoreTest extends TestCase
{
    /**
     * @param class-string $class
     * @param array<string, mixed> $overrides
     */
    private function map(string $class, array $overrides = []): Store
    {
        $result = match ($class) {
            MyResult::class => MyResult::fromValue(SyntheticResult::of($class, $overrides), 'store_my'),
            EditResult::class => EditResult::fromValue(SyntheticResult::of($class, $overrides), 'store_edit'),
            default => throw new \LogicException($class),
        };

        return (new ModelMapper())->store($result);
    }

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function resultClasses(): iterable
    {
        yield 'store_my' => [MyResult::class];
        yield 'store_edit' => [EditResult::class];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('resultClasses')]
    public function mapsEveryField(string $class): void
    {
        $store = $this->map($class, [
            'id' => 'store-1',
            'slug' => 'synthetic-shop',
            'storeName' => 'Synthetic Shop',
            'avatar' => 'AVATAR_7',
            'banner' => 'BANNER_3',
            'customAvatar' => 'https://img.invalid/a.png',
            'steamAvatar' => 'https://img.invalid/s.png',
            'deliveryPromise' => 'HOUR1',
            'isTopSeller' => true,
            'isKycVerified' => true,
            'isAchievementsPublic' => true,
            'level' => 4,
            'points' => 120,
            'leftPoints' => 30,
            'years' => 2,
            'registeredAt' => '2024-01-02T03:04:05+00:00',
        ]);

        self::assertSame('store-1', $store->id);
        self::assertSame('synthetic-shop', $store->slug);
        self::assertSame('Synthetic Shop', $store->storeName);
        self::assertSame(StoreAvatar::Avatar7, $store->avatar);
        self::assertSame(StoreBanner::Banner3, $store->banner);
        self::assertSame('https://img.invalid/a.png', $store->customAvatarUrl);
        self::assertSame('https://img.invalid/s.png', $store->steamAvatar);
        self::assertSame(DeliveryPromise::Hour1, $store->deliveryPromise);
        self::assertTrue($store->isTopSeller);
        self::assertTrue($store->isKycVerified);
        self::assertSame([4, 120, 30, 2], [$store->level, $store->points, $store->leftPoints, $store->years]);
        self::assertSame('2024-01-02T03:04:05+00:00', $store->registeredAt->format(\DATE_ATOM));
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('resultClasses')]
    public function keepsTheFiveVisibilityFlagsApart(string $class): void
    {
        foreach (['isAchievementsPublic', 'isFavoritesPublic', 'isItemsOnSalePublic', 'isStoreNamePublic', 'isTradeHistoryPublic'] as $on) {
            $store = $this->map($class, [$on => true]);

            self::assertSame(
                [$on === 'isAchievementsPublic', $on === 'isFavoritesPublic', $on === 'isItemsOnSalePublic', $on === 'isStoreNamePublic', $on === 'isTradeHistoryPublic'],
                [$store->isAchievementsPublic, $store->isFavoritesPublic, $store->isItemsOnSalePublic, $store->isStoreNamePublic, $store->isTradeHistoryPublic],
                $on,
            );
            self::assertFalse($store->isTopSeller);
            self::assertFalse($store->isKycVerified);
        }
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('resultClasses')]
    public function mapsAnEmptyStoreToNullsAndNoAchievements(string $class): void
    {
        $store = $this->map($class, ['achievements' => null]);

        self::assertNull($store->id);
        self::assertNull($store->avatar);
        self::assertNull($store->banner);
        self::assertNull($store->deliveryPromise);
        self::assertNull($store->customAvatarUrl);
        self::assertNull($store->level);
        self::assertSame([], $store->achievements);
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('resultClasses')]
    public function aNewAvatarBannerAndDeliveryPromiseStayUnknown(string $class): void
    {
        $store = $this->map($class, ['avatar' => 'AVATAR_99', 'banner' => 'BANNER_99', 'deliveryPromise' => 'MINUTES5']);

        self::assertInstanceOf(UnknownEnumValue::class, $store->avatar);
        self::assertSame('AVATAR_99', $store->avatar->value);
        self::assertInstanceOf(UnknownEnumValue::class, $store->banner);
        self::assertSame('BANNER_99', $store->banner->value);
        self::assertInstanceOf(UnknownEnumValue::class, $store->deliveryPromise);
        self::assertSame('MINUTES5', $store->deliveryPromise->value);
    }

    /**
     * @param class-string $class
     *
     * @return list<array<string, mixed>>
     */
    private function achievementsOf(string $class): array
    {
        $achievements = $class === MyResult::class ? MyAchievements::class : EditAchievements::class;
        $boolean = $class === MyResult::class ? MyBooleanParam::class : EditBooleanParam::class;
        $string = $class === MyResult::class ? MyStringParam::class : EditStringParam::class;

        return [
            SyntheticResult::of($achievements, [
                'name' => 'PRO_TRADER',
                'points' => 50,
                'status' => 'IN_PROGRESS',
                'type' => 'PROGRESS',
                'unlockedAt' => '2025-06-07T08:09:10+00:00',
                'params' => [
                    SyntheticResult::typed('AchievementBooleanParam', $boolean, ['name' => 'CONDITION_BUY_KNIFE', 'booleanValue' => true]),
                    null,
                    SyntheticResult::typed('AchievementStringParam', $string, ['name' => 'CONDITION_TRADE_AMOUNT', 'stringValue' => '12.50']),
                    ['__typename' => 'AchievementFuture', 'name' => 'X', 'value' => 1],
                    SyntheticResult::typed('AchievementBooleanParam', $boolean, ['name' => 'CONDITION_BUY_PISTOL', 'booleanValue' => false]),
                    SyntheticResult::typed('AchievementStringParam', $string, ['name' => 'CONDITION_FUTURE', 'stringValue' => 'x']),
                ],
            ]),
            SyntheticResult::of($achievements, ['name' => 'NEW_BADGE', 'status' => 'PAUSED', 'type' => 'QUEST', 'params' => null]),
        ];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('resultClasses')]
    public function mapsAchievementsWithBothParamKindsAndSkipsUnknownMembers(string $class): void
    {
        $store = $this->map($class, ['achievements' => $this->achievementsOf($class)]);

        self::assertCount(2, $store->achievements);
        $first = $store->achievements[0];
        self::assertSame(AchievementName::ProTrader, $first->name);
        self::assertSame(50, $first->points);
        self::assertSame(AchievementStatus::InProgress, $first->status);
        self::assertSame(AchievementKind::Progress, $first->kind);
        self::assertSame('2025-06-07T08:09:10+00:00', $first->unlockedAt?->format(\DATE_ATOM));
        self::assertCount(4, $first->params);
        self::assertSame(
            [[AchievementCondition::BuyKnife, true], [AchievementCondition::TradeAmount, '12.50'], [AchievementCondition::BuyPistol, false]],
            array_map(static fn(AchievementParam $p): array => [$p->name, $p->value], \array_slice($first->params, 0, 3)),
        );
        $unknown = $first->params[3];
        self::assertInstanceOf(UnknownEnumValue::class, $unknown->name);
        self::assertSame('CONDITION_FUTURE', $unknown->name->value);
        self::assertSame('x', $unknown->value);
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('resultClasses')]
    public function anUnknownAchievementAndNullParamsStayUsable(string $class): void
    {
        $second = $this->map($class, ['achievements' => $this->achievementsOf($class)])->achievements[1] ?? self::fail('no second achievement');

        self::assertInstanceOf(UnknownEnumValue::class, $second->name);
        self::assertSame('NEW_BADGE', $second->name->value);
        self::assertInstanceOf(UnknownEnumValue::class, $second->status);
        self::assertSame('PAUSED', $second->status->value);
        self::assertInstanceOf(UnknownEnumValue::class, $second->kind);
        self::assertSame('QUEST', $second->kind->value);
        self::assertNull($second->unlockedAt);
        self::assertSame([], $second->params);
    }
}
