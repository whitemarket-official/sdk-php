<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tools\LiveSmoke;

use WhiteMarket\Sdk\Generated\Enum\Currency;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\ForwardPaginationInput;
use WhiteMarket\Sdk\Generated\Input\SteamLinkInput;
use WhiteMarket\Sdk\Models\Uuid;

/**
 * One read-only call with minimal variables for EVERY generated query operation, keyed by the operation class name
 * (`src/Generated/Operation/Query/<Key>.php`). A test pins the key set to the generated set, so a schema sync that
 * adds a query fails until it is added here.
 */
final class QueryCatalog
{
    /** Synthetic trade offer URL: well-formed, belongs to no real account. */
    private const SYNTHETIC_TRADE_URL = 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=abcdefgh';

    private function __construct() {}

    /**
     * @return array<string, \Closure(): mixed>
     */
    public static function steps(GeneratedClient $api): array
    {
        $first = new ForwardPaginationInput(first: 1);
        $unknownId = Uuid::v4();

        return [
            'DealBalanceHold' => static fn(): mixed => $api->dealBalanceHold(Currency::USD),
            'DealBalancesHold' => $api->dealBalancesHold(...),
            'DealBuyerList' => static fn(): mixed => $api->dealBuyerList(),
            'DealBuyerStatuses' => static fn(): mixed => $api->dealBuyerStatuses(['non-existent']),
            'DealHistory' => static fn(): mixed => $api->dealHistory($first),
            'DealHistoryItem' => static fn(): mixed => $api->dealHistoryItem(id: $unknownId),
            'DealItem' => static fn(): mixed => $api->dealItem(id: $unknownId),
            'DealSellerList' => static fn(): mixed => $api->dealSellerList(),
            'InventoryMy' => static fn(): mixed => $api->inventoryMy($first),
            'MarketCsgoStickersSuggestion' => static fn(): mixed => $api->marketCsgoStickersSuggestion('a', limit: 1),
            'MarketItem' => static fn(): mixed => $api->marketItem($unknownId),
            'MarketList' => static fn(): mixed => $api->marketList($first),
            'MarketMy' => static fn(): mixed => $api->marketMy($first),
            'MarketTradeUrl' => static fn(): mixed => $api->marketTradeUrl(new SteamLinkInput(self::SYNTHETIC_TRADE_URL)),
            'OrderList' => static fn(): mixed => $api->orderList($first),
            'OrderMy' => static fn(): mixed => $api->orderMy($first),
            'OrderMyActive' => static fn(): mixed => $api->orderMyActive($first),
            'OrderMyFulfilled' => static fn(): mixed => $api->orderMyFulfilled($first),
            'OrderMyInactive' => static fn(): mixed => $api->orderMyInactive($first),
            'PersonProfile' => $api->personProfile(...),
            'ProfileId' => $api->profileId(...),
            'ProfileSteamProxyGet' => $api->profileSteamProxyGet(...),
            'StoreMy' => $api->storeMy(...),
            'TestPing' => $api->testPing(...),
            'WalletBalance' => static fn(): mixed => $api->walletBalance(Currency::USD),
            'WalletBalances' => $api->walletBalances(...),
            'WalletCreditAvailable' => $api->walletCreditAvailable(...),
            'WalletCreditMax' => $api->walletCreditMax(...),
            'WalletCreditUsed' => $api->walletCreditUsed(...),
            'WalletDeposits' => $api->walletDeposits(...),
            'WalletTransactions' => static fn(): mixed => $api->walletTransactions($first),
            'WalletTransfers' => static fn(): mixed => $api->walletTransfers($first),
            'WalletWithdrawals' => $api->walletWithdrawals(...),
        ];
    }
}
