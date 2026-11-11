<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Models\AchievementCondition;
use WhiteMarket\Sdk\Models\AchievementKind;
use WhiteMarket\Sdk\Models\AchievementName;
use WhiteMarket\Sdk\Models\AchievementStatus;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\Color;
use WhiteMarket\Sdk\Models\CryptoCurrency;
use WhiteMarket\Sdk\Models\CryptoNetwork;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DealAction;
use WhiteMarket\Sdk\Models\DealHistorySortField;
use WhiteMarket\Sdk\Models\DealHistoryStatus;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\DepositStatus;
use WhiteMarket\Sdk\Models\Exterior;
use WhiteMarket\Sdk\Models\ItemCategory;
use WhiteMarket\Sdk\Models\ItemSubcategory;
use WhiteMarket\Sdk\Models\Locale;
use WhiteMarket\Sdk\Models\LogicOperand;
use WhiteMarket\Sdk\Models\OfferCancelReason;
use WhiteMarket\Sdk\Models\OfferType;
use WhiteMarket\Sdk\Models\PaymentProvider;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\ProductSortField;
use WhiteMarket\Sdk\Models\Rarity;
use WhiteMarket\Sdk\Models\SortDirection;
use WhiteMarket\Sdk\Models\StickerFilm;
use WhiteMarket\Sdk\Models\StoreAvatar;
use WhiteMarket\Sdk\Models\StoreBanner;
use WhiteMarket\Sdk\Models\TransactionFeeType;
use WhiteMarket\Sdk\Models\TransactionReason;
use WhiteMarket\Sdk\Models\TransactionSortField;
use WhiteMarket\Sdk\Models\TransactionStatus;
use WhiteMarket\Sdk\Models\TransferStatus;
use WhiteMarket\Sdk\Models\WalletMethod;
use WhiteMarket\Sdk\Models\WithdrawalStatus;

/**
 * Every value of a high-level enum must exist in the generated enum it is sent as, or `from()` in
 * `ProductFilter::toInput()` would throw. The reverse is deliberately not asserted: a schema sync that adds a value
 * must stay green, and a raw `MarketProductSearchInput` reaches the new value meanwhile.
 */
#[CoversNothing]
final class HighLevelEnumParityTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string<\BackedEnum>, class-string<\BackedEnum>}>
     */
    public static function pairs(): iterable
    {
        yield 'App' => [App::class, Wire\SteamApp::class];
        yield 'Exterior' => [Exterior::class, Wire\CSGOExterior::class];
        yield 'ItemCategory' => [ItemCategory::class, Wire\CSGOCategory::class];
        yield 'ItemSubcategory' => [ItemSubcategory::class, Wire\CSGOSubcategory::class];
        yield 'Phase' => [Phase::class, Wire\CSGOPhase::class];
        yield 'Rarity' => [Rarity::class, Wire\CSGORarity::class];
        yield 'StickerFilm' => [StickerFilm::class, Wire\CSGOStickerFilm::class];
        yield 'LogicOperand' => [LogicOperand::class, Wire\LogicOperand::class];
        yield 'Color' => [Color::class, Wire\CatalogColor::class];
        yield 'OfferType' => [OfferType::class, Wire\MarketOfferType::class];
        yield 'ProductSortField' => [ProductSortField::class, Wire\MarketProductSortField::class];
        yield 'SortDirection' => [SortDirection::class, Wire\SortType::class];
        yield 'DeliveryType' => [DeliveryType::class, Wire\DeliveryType::class];
        yield 'DeliveryPromise' => [DeliveryPromise::class, Wire\DeliveryPromise::class];
        yield 'Currency' => [Currency::class, Wire\Currency::class];
        yield 'DealAction' => [DealAction::class, Wire\MarketDealActionType::class];
        yield 'DealHistoryStatus' => [DealHistoryStatus::class, Wire\MarketDealHistoryStatus::class];
        yield 'DealHistorySortField' => [DealHistorySortField::class, Wire\MarketDealHistorySortField::class];
        yield 'OfferCancelReason' => [OfferCancelReason::class, Wire\MarketDealExternalCancelReason::class];
        yield 'DealStatus' => [DealStatus::class, Wire\MarketDealStatus::class];
        yield 'TransactionReason' => [TransactionReason::class, Wire\WalletTransactionReason::class];
        yield 'TransactionStatus' => [TransactionStatus::class, Wire\WalletTransactionStatus::class];
        yield 'TransactionFeeType' => [TransactionFeeType::class, Wire\WalletTransactionFeeType::class];
        yield 'TransferStatus' => [TransferStatus::class, Wire\WalletTransferStatus::class];
        yield 'DepositStatus' => [DepositStatus::class, Wire\WalletDepositStatus::class];
        yield 'WithdrawalStatus' => [WithdrawalStatus::class, Wire\WalletWithdrawalStatus::class];
        yield 'PaymentProvider' => [PaymentProvider::class, Wire\PaymentProvider::class];
        yield 'WalletMethod' => [WalletMethod::class, Wire\WalletMethodEnum::class];
        yield 'CryptoCurrency' => [CryptoCurrency::class, Wire\CryptoCurrency::class];
        yield 'CryptoNetwork' => [CryptoNetwork::class, Wire\CryptoNetwork::class];
        yield 'Locale' => [Locale::class, Wire\Locale::class];
        yield 'TransactionSortField' => [TransactionSortField::class, Wire\WalletTransactionSortField::class];
        yield 'StoreAvatar' => [StoreAvatar::class, Wire\AvatarType::class];
        yield 'StoreBanner' => [StoreBanner::class, Wire\BannerType::class];
        yield 'AchievementName' => [AchievementName::class, Wire\AchievementType::class];
        yield 'AchievementStatus' => [AchievementStatus::class, Wire\AchievementStatusType::class];
        yield 'AchievementCondition' => [AchievementCondition::class, Wire\AchievementParamType::class];
        yield 'AchievementKind' => [AchievementKind::class, Wire\AchievementTypeType::class];
    }

    /**
     * @param class-string<\BackedEnum> $highLevel
     * @param class-string<\BackedEnum> $generated
     */
    #[Test]
    #[DataProvider('pairs')]
    public function everyHighLevelValueExistsInTheGeneratedEnum(string $highLevel, string $generated): void
    {
        $missing = [];
        foreach ($highLevel::cases() as $case) {
            if ($generated::tryFrom((string) $case->value) === null) {
                $missing[] = $case->value;
            }
        }

        self::assertSame([], $missing);
        self::assertNotSame([], $highLevel::cases());
    }

    #[Test]
    public function appSkipsTheSchemaUnknownValue(): void
    {
        self::assertNull(App::tryFrom('UNKNOWN'));
        self::assertNotNull(Wire\SteamApp::tryFrom('UNKNOWN'));
    }
}
