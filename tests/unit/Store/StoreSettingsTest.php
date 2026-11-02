<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Store;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\StoreAvatar;
use WhiteMarket\Sdk\Models\StoreBanner;
use WhiteMarket\Sdk\Store\StoreSettings;

#[CoversClass(StoreSettings::class)]
final class StoreSettingsTest extends TestCase
{
    /**
     * Each constructor parameter alone -> the encoded `PersonStoreInput` it must produce.
     *
     * @return \Iterator<string, array{StoreSettings, array<string, mixed>}>
     */
    public static function singleParameters(): \Iterator
    {
        yield 'empty settings -> nothing' => [new StoreSettings(), []];
        yield 'avatar' => [new StoreSettings(avatar: StoreAvatar::Avatar12), ['avatar' => 'AVATAR_12']];
        yield 'avatar steam' => [new StoreSettings(avatar: StoreAvatar::Steam), ['avatar' => 'STEAM']];
        yield 'banner' => [new StoreSettings(banner: StoreBanner::Banner8), ['banner' => 'BANNER_8']];
        yield 'customAvatar -> encodedCustomAvatar' => [new StoreSettings(customAvatar: 'aGVsbG8='), ['encodedCustomAvatar' => 'aGVsbG8=']];
        yield 'storeName' => [new StoreSettings(storeName: 'Synthetic Shop'), ['storeName' => 'Synthetic Shop']];
        yield 'empty storeName is sent' => [new StoreSettings(storeName: ''), ['storeName' => '']];
        yield 'achievementsPublic true' => [new StoreSettings(isAchievementsPublic: true), ['isAchievementsPublic' => true]];
        yield 'achievementsPublic false is sent' => [new StoreSettings(isAchievementsPublic: false), ['isAchievementsPublic' => false]];
        yield 'favoritesPublic' => [new StoreSettings(isFavoritesPublic: false), ['isFavoritesPublic' => false]];
        yield 'itemsOnSalePublic' => [new StoreSettings(isItemsOnSalePublic: true), ['isItemsOnSalePublic' => true]];
        yield 'storeNamePublic' => [new StoreSettings(isStoreNamePublic: false), ['isStoreNamePublic' => false]];
        yield 'tradeHistoryPublic' => [new StoreSettings(isTradeHistoryPublic: true), ['isTradeHistoryPublic' => true]];
    }

    /**
     * @param array<string, mixed> $expected
     */
    #[Test]
    #[DataProvider('singleParameters')]
    public function encodesEachParameterToItsWireField(StoreSettings $settings, array $expected): void
    {
        self::assertSame($expected, $settings->toInput()->toArray());
    }

    #[Test]
    public function encodesEveryParameterTogether(): void
    {
        $settings = new StoreSettings(StoreAvatar::Avatar1, StoreBanner::Banner1, 'aGk=', 'Shop', true, false, true, false, true);

        self::assertSame([
            'avatar' => 'AVATAR_1',
            'banner' => 'BANNER_1',
            'encodedCustomAvatar' => 'aGk=',
            'isAchievementsPublic' => true,
            'isFavoritesPublic' => false,
            'isItemsOnSalePublic' => true,
            'isStoreNamePublic' => false,
            'isTradeHistoryPublic' => true,
            'storeName' => 'Shop',
        ], $settings->toInput()->toArray());
    }
}
