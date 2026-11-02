<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Fragment\CSGOStickerFields;
use WhiteMarket\Sdk\Generated\Fragment\MoneyFields;
use WhiteMarket\Sdk\Generated\Fragment\PersonPublicFields;
use WhiteMarket\Sdk\Generated\Operation\Query\DealBuyerStatuses\Result as DealStateResult;
use WhiteMarket\Sdk\Profile\Profile;
use WhiteMarket\Sdk\Profile\TradingCompetitionStat;
use WhiteMarket\Sdk\Store\Achievement;
use WhiteMarket\Sdk\Store\AchievementParam;
use WhiteMarket\Sdk\Store\Store;
use WhiteMarket\Sdk\Wallet\Debt;
use WhiteMarket\Sdk\Wallet\Deposit;
use WhiteMarket\Sdk\Wallet\PersonSummary;
use WhiteMarket\Sdk\Wallet\WalletTransaction;
use WhiteMarket\Sdk\Wallet\WalletTransfer;
use WhiteMarket\Sdk\Wallet\Withdrawal;

/**
 * The one place where generated result classes become high-level models (SDK_SPEC 4): one method per model.
 *
 * Every operation has its own generated classes, so one `MarketProduct` arrives as a dozen different classes with the
 * same fields. The mappers take object shapes (`@phpstan-type` below) instead of class lists: PHPStan checks each
 * call site against the shape, and a new operation needs no change here. Fields typed by a GraphQL interface
 * (`item`, `description`, `params`) arrive as an empty marker interface or {@see \WhiteMarket\Sdk\GraphQL\UnknownTypeValue};
 * they are checked at run time against the shape of the one implementation this SDK knows (`CSGOInventoryItem`,
 * `CSGOSteamItem`), and anything else maps to null, so an item type the server adds later never throws.
 *
 * Enums are matched by their wire value, so a high-level enum may know a value the schema snapshot lacks (`QUEUED`)
 * and a value neither knows stays an {@see UnknownEnumValue} (CLAUDE.md rule 5).
 *
 * `dealState()` takes its class: `MarketDealState` is selected by one operation only.
 *
 * Every method that decodes a decimal takes the JSON path of its value, used in the {@see DecodeException} of a malformed decimal.
 *
 * @phpstan-type WireEnum \BackedEnum|UnknownEnumValue
 * @phpstan-type PageInfoSource object{endCursor: ?string, hasNextPage: bool}
 * @phpstan-type ItemSource object{id: string, assetId: string, classId: string, instanceId: string, nameHash: ?string, float: ?string, paintSeed: ?string, paintIndex: ?string, phase: ?string, link: ?string}
 * @phpstan-type SteamItemSource object{name: string, isStatTrak: bool, isSouvenir: bool}
 * @phpstan-type ProductSource object{id: string, slug: string, price: MoneyFields, createdAt: \DateTimeImmutable, isOwner: bool, deliveryType: WireEnum, deliveryPromise: WireEnum, item: mixed}
 * @phpstan-type ProductConnectionSource object{edges: ?list<?object{node: ?ProductSource}>, pageInfo: ?PageInfoSource, totalCount: ?int}
 * @phpstan-type InventoryConnectionSource object{edges: ?list<?object{node: ?object}>, pageInfo: ?PageInfoSource, totalCount: ?int}
 * @phpstan-type DealHistoryConnectionSource object{edges: ?list<?object{node: ?DealHistorySource}>, pageInfo: ?PageInfoSource, totalCount: ?int}
 * @phpstan-type StickerSuggestionsSource object{moreCount: int, suggestions: ?list<?object{name: string, title: string, icon: ?string, film: WireEnum|null, total: int}>}
 * @phpstan-type ProductHistorySource object{id: string, status: WireEnum, price: MoneyFields, archivedAt: \DateTimeImmutable, deliveryType: WireEnum, assetId: string, classId: string, instanceId: string, description: mixed, params: mixed}
 * @phpstan-type DealSource object{id: string, customId: ?string, status: WireEnum, price: MoneyFields, createdAt: \DateTimeImmutable, deliveryType: WireEnum, steamId: ?string, tradeId: ?string, isTradeProtected: bool, settlementTimeAt: ?\DateTimeImmutable, expiredAt: \DateTimeImmutable, isCancelByBuyerSupported: bool, cancelByBuyerNeedWait: ?int, receiverTradeUrl: ?string, product: ProductHistorySource, item: mixed}
 * @phpstan-type DealHistorySource object{id: string, customId: ?string, status: WireEnum, price: MoneyFields, createdAt: \DateTimeImmutable, deliveryType: WireEnum, steamId: ?string, cancelReason: WireEnum|null, archivedAt: \DateTimeImmutable, reversePenaltyValue: ?MoneyFields, reverseRefundValue: ?MoneyFields, product: ProductHistorySource}
 * @phpstan-type DepositSource object{id: string, createdAt: \DateTimeImmutable, completedAt: ?\DateTimeImmutable, expiredAt: ?\DateTimeImmutable, provider: WireEnum, status: WireEnum, amount: MoneyFields, comment: ?string, providerCorrespondingId: ?string, providerPaymentPage: ?string, airwallexSecret: ?Secret, person: PersonPublicFields}
 * @phpstan-type WithdrawalSource object{id: string, createdAt: \DateTimeImmutable, completedAt: ?\DateTimeImmutable, expiredAt: ?\DateTimeImmutable, provider: WireEnum, status: WireEnum, amount: MoneyFields, comment: ?string, providerCorrespondingId: ?string, person: PersonPublicFields}
 * @phpstan-type WalletTransactionSource object{id: string, createdAt: \DateTimeImmutable, reason: WireEnum, reasonId: string, status: WireEnum, amount: MoneyFields, fee: ?MoneyFields, comment: ?string, initialCurrency: WireEnum|null, marketDealActionType: WireEnum|null, method: WireEnum|null, network: WireEnum|null, withdrawalAddress: ?string, person: PersonPublicFields, counterparty: ?PersonPublicFields}
 * @phpstan-type WalletTransferSource object{id: string, createdAt: \DateTimeImmutable, completedAt: ?\DateTimeImmutable, status: WireEnum, amount: MoneyFields, comment: ?string, from: PersonPublicFields, to: PersonPublicFields}
 * @phpstan-type WalletTransactionConnectionSource object{edges: ?list<?object{node: ?WalletTransactionSource}>, pageInfo: ?PageInfoSource, totalCount: ?int}
 * @phpstan-type WalletTransferConnectionSource object{edges: ?list<?object{node: ?WalletTransferSource}>, pageInfo: ?PageInfoSource, totalCount: ?int}
 * @phpstan-type ProfileSource object{id: string, registeredAt: \DateTimeImmutable, locale: WireEnum, email: ?string, isEmailConfirmed: bool, canBuy: bool, canSell: bool, canDeposit: bool, canWithdraw: bool, isKycVerified: bool, isPartnerTokenExist: bool, marketOrderAutoActivate: bool, deliveryPromiseSelected: WireEnum|null, steamId: ?string, steamName: ?string, steamLevel: ?int, steamAvatarMedium: ?string, steamAvatarFull: ?string, steamRegisteredAt: ?\DateTimeImmutable, steamTradeUrl: ?string, inventoryUpdatedAt: ?\DateTimeImmutable, discountPercent: ?int, discountActiveTo: ?\DateTimeImmutable, sellBannedUntil: ?\DateTimeImmutable, vacationUntil: ?\DateTimeImmutable, level: ?int, points: ?int, leftPoints: ?int, percent: ?string, referralsCount: ?int, inviterCode: ?string, referrerCode: ?string, referralTag: ?string, countryCodeOfResidence: ?string, countryCodeOfCurrentLocation: ?string, isAchievementsEnabled: bool, isSkinTranslationEnabled: bool, isMcBattlePassEnabled: bool, tradingCompetitionStat: object{position: ?int, topLevel: ?int}}
 * @phpstan-type AchievementSource object{name: WireEnum, points: int, status: WireEnum, type: WireEnum, unlockedAt: ?\DateTimeImmutable, params: ?list<mixed>}
 * @phpstan-type StoreSource object{id: ?string, slug: ?string, storeName: string, avatar: ?string, banner: ?string, customAvatar: ?string, steamAvatar: ?string, deliveryPromise: WireEnum|null, isTopSeller: bool, isKycVerified: bool, isAchievementsPublic: bool, isFavoritesPublic: bool, isItemsOnSalePublic: bool, isStoreNamePublic: bool, isTradeHistoryPublic: bool, level: ?int, points: ?int, leftPoints: ?int, years: ?int, registeredAt: \DateTimeImmutable, achievements: ?list<AchievementSource>}
 * @phpstan-type DebtSource object{id: string, createdAt: \DateTimeImmutable, amount: MoneyFields}
 *
 * @internal used by the high-level API; not part of the public API
 */
final class ModelMapper
{
    /**
     * Maps every source of a list; the JSON path of the entry at index `$i` is `<path>[<i>]`.
     *
     * @template TSource
     * @template TItem
     *
     * @param iterable<int, TSource> $sources
     * @param \Closure(TSource, string): TItem $map argument: the source and its JSON path
     *
     * @return list<TItem>
     *
     * @throws DecodeException
     */
    public function list(iterable $sources, string $path, \Closure $map): array
    {
        $items = [];
        foreach ($sources as $i => $source) {
            $items[] = $map($source, \sprintf('%s[%d]', $path, $i));
        }

        return $items;
    }

    /**
     * @throws DecodeException when the amount is not a decimal string
     */
    public function money(MoneyFields $money, string $path): Money
    {
        return new Money($this->decimal($money->value, $path . '.value'), $this->enum(Currency::class, $money->currency));
    }

    /**
     * @throws DecodeException
     */
    public function dealState(DealStateResult $state, string $path): DealState
    {
        return new DealState(
            id: $state->id,
            customId: $state->customId,
            status: $this->enum(DealStatus::class, $state->status),
            cancelReason: $this->optionalEnum(DealCancelReason::class, $state->cancelReason),
            price: $this->money($state->price, $path . '.price'),
            steamId: $state->steamId,
            tradeId: $state->tradeId,
            isTradeProtected: $state->isTradeProtected,
            settlementTimeAt: $state->settlementTimeAt,
            reversePenalty: $this->optionalMoney($state->reversePenaltyValue, $path . '.reversePenaltyValue'),
            reverseRefund: $this->optionalMoney($state->reverseRefundValue, $path . '.reverseRefundValue'),
        );
    }

    /**
     * The status snapshot of a deal read with `deal_item` / `deal_history_item` (a seller's deal is not in
     * `deal_buyer_statuses`). Fields only the status query reports stay null.
     */
    public function dealStateOf(#[\SensitiveParameter] Deal $deal): DealState
    {
        return new DealState(
            id: $deal->id,
            customId: $deal->customId,
            status: $deal->status,
            cancelReason: $deal->cancelReason,
            price: $deal->price,
            steamId: $deal->steamId,
            tradeId: $deal->tradeId,
            isTradeProtected: $deal->isTradeProtected,
            settlementTimeAt: $deal->settlementTimeAt,
            reversePenalty: $deal->reversePenalty,
            reverseRefund: $deal->reverseRefund,
        );
    }

    /**
     * @param ProductSource $product
     *
     * @throws DecodeException
     */
    public function product(object $product, string $path): Product
    {
        return new Product(
            id: $product->id,
            slug: $product->slug,
            price: $this->money($product->price, $path . '.price'),
            createdAt: $product->createdAt,
            isOwner: $product->isOwner,
            deliveryType: $this->enum(DeliveryType::class, $product->deliveryType),
            deliveryPromise: $this->enum(DeliveryPromise::class, $product->deliveryPromise),
            item: $this->inventoryItem($product->item, null, $path . '.item'),
        );
    }

    /**
     * A page of a product connection. A null edge or node carries no product and is skipped; a missing `pageInfo`
     * means there is no next page (the schema allows both).
     *
     * @param ProductConnectionSource $connection
     *
     * @return Page<Product>
     *
     * @throws DecodeException
     */
    public function productPage(object $connection, string $path): Page
    {
        return $this->page($connection, $path, fn(object $node, string $nodePath): Product => $this->product($node, $nodePath));
    }

    /**
     * A page of the inventory; the same rules as {@see productPage()}. A node that is not a CS2 inventory item (a type
     * this SDK does not know) is skipped like a null node.
     *
     * @param InventoryConnectionSource $connection
     *
     * @return Page<InventoryItem>
     *
     * @throws DecodeException
     */
    public function inventoryPage(object $connection, string $path): Page
    {
        return $this->page($connection, $path, fn(object $node, string $nodePath): ?InventoryItem => $this->inventoryItem($node, null, $nodePath));
    }

    /**
     * A page of the deal history; the same rules as {@see productPage()}.
     *
     * @param DealHistoryConnectionSource $connection
     *
     * @return Page<Deal>
     *
     * @throws DecodeException
     */
    public function archivedDealPage(object $connection, string $path): Page
    {
        return $this->page($connection, $path, fn(object $node, string $nodePath): Deal => $this->archivedDeal($node, $nodePath));
    }

    /**
     * @param WalletTransactionConnectionSource $connection
     *
     * @return Page<WalletTransaction>
     *
     * @throws DecodeException
     */
    public function walletTransactionPage(object $connection, string $path): Page
    {
        return $this->page($connection, $path, fn(object $node, string $nodePath): WalletTransaction => $this->walletTransaction($node, $nodePath));
    }

    /**
     * @param WalletTransferConnectionSource $connection
     *
     * @return Page<WalletTransfer>
     *
     * @throws DecodeException
     */
    public function walletTransferPage(object $connection, string $path): Page
    {
        return $this->page($connection, $path, fn(object $node, string $nodePath): WalletTransfer => $this->walletTransfer($node, $nodePath));
    }

    /**
     * @param DepositSource $deposit
     *
     * @throws DecodeException
     */
    public function deposit(object $deposit, string $path): Deposit
    {
        return new Deposit(
            id: $deposit->id,
            createdAt: $deposit->createdAt,
            completedAt: $deposit->completedAt,
            expiredAt: $deposit->expiredAt,
            provider: $this->enum(PaymentProvider::class, $deposit->provider),
            status: $this->enum(DepositStatus::class, $deposit->status),
            amount: $this->money($deposit->amount, $path . '.amount'),
            comment: $deposit->comment,
            providerCorrespondingId: $deposit->providerCorrespondingId,
            providerPaymentPage: $deposit->providerPaymentPage,
            airwallexSecret: $deposit->airwallexSecret,
            person: $this->personSummary($deposit->person),
        );
    }

    /**
     * @param WithdrawalSource $withdrawal
     *
     * @throws DecodeException
     */
    public function withdrawal(object $withdrawal, string $path): Withdrawal
    {
        return new Withdrawal(
            id: $withdrawal->id,
            createdAt: $withdrawal->createdAt,
            completedAt: $withdrawal->completedAt,
            expiredAt: $withdrawal->expiredAt,
            provider: $this->enum(PaymentProvider::class, $withdrawal->provider),
            status: $this->enum(WithdrawalStatus::class, $withdrawal->status),
            amount: $this->money($withdrawal->amount, $path . '.amount'),
            comment: $withdrawal->comment,
            providerCorrespondingId: $withdrawal->providerCorrespondingId,
            person: $this->personSummary($withdrawal->person),
        );
    }

    /**
     * @param WalletTransactionSource $transaction
     *
     * @throws DecodeException
     */
    public function walletTransaction(object $transaction, string $path): WalletTransaction
    {
        return new WalletTransaction(
            id: $transaction->id,
            createdAt: $transaction->createdAt,
            reason: $this->enum(TransactionReason::class, $transaction->reason),
            reasonId: $transaction->reasonId,
            status: $this->enum(TransactionStatus::class, $transaction->status),
            amount: $this->money($transaction->amount, $path . '.amount'),
            fee: $this->optionalMoney($transaction->fee, $path . '.fee'),
            comment: $transaction->comment,
            initialCurrency: $this->optionalEnum(CryptoCurrency::class, $transaction->initialCurrency),
            dealAction: $this->optionalEnum(DealAction::class, $transaction->marketDealActionType),
            method: $this->optionalEnum(WalletMethod::class, $transaction->method),
            network: $this->optionalEnum(CryptoNetwork::class, $transaction->network),
            withdrawalAddress: $transaction->withdrawalAddress,
            person: $this->personSummary($transaction->person),
            counterparty: $transaction->counterparty instanceof PersonPublicFields ? $this->personSummary($transaction->counterparty) : null,
        );
    }

    /**
     * @param WalletTransferSource $transfer
     *
     * @throws DecodeException
     */
    public function walletTransfer(object $transfer, string $path): WalletTransfer
    {
        return new WalletTransfer(
            id: $transfer->id,
            createdAt: $transfer->createdAt,
            completedAt: $transfer->completedAt,
            status: $this->enum(TransferStatus::class, $transfer->status),
            amount: $this->money($transfer->amount, $path . '.amount'),
            comment: $transfer->comment,
            from: $this->personSummary($transfer->from),
            to: $this->personSummary($transfer->to),
        );
    }

    /**
     * @param DebtSource $debt
     *
     * @throws DecodeException
     */
    public function debt(object $debt, string $path): Debt
    {
        return new Debt($debt->id, $debt->createdAt, $this->money($debt->amount, $path . '.amount'));
    }

    /**
     * The account of `person_profile` and of the `email_*` mutations (one selection, `PersonFull`). An empty trade URL
     * means none is set and maps to null; a set one is wrapped in a {@see Secret} (it carries the trade token).
     *
     * @param ProfileSource $profile
     */
    public function profile(object $profile): Profile
    {
        return new Profile(
            id: $profile->id,
            registeredAt: $profile->registeredAt,
            locale: $this->enum(Locale::class, $profile->locale),
            email: $profile->email,
            isEmailConfirmed: $profile->isEmailConfirmed,
            canBuy: $profile->canBuy,
            canSell: $profile->canSell,
            canDeposit: $profile->canDeposit,
            canWithdraw: $profile->canWithdraw,
            isKycVerified: $profile->isKycVerified,
            hasPartnerToken: $profile->isPartnerTokenExist,
            ordersAutoActivate: $profile->marketOrderAutoActivate,
            deliveryPromise: $this->optionalEnum(DeliveryPromise::class, $profile->deliveryPromiseSelected),
            steamId: $profile->steamId,
            steamName: $profile->steamName,
            steamLevel: $profile->steamLevel,
            steamAvatarMedium: $profile->steamAvatarMedium,
            steamAvatarFull: $profile->steamAvatarFull,
            steamRegisteredAt: $profile->steamRegisteredAt,
            steamTradeUrl: $this->optionalSecret($profile->steamTradeUrl),
            inventoryUpdatedAt: $profile->inventoryUpdatedAt,
            discountPercent: $profile->discountPercent,
            discountActiveTo: $profile->discountActiveTo,
            sellBannedUntil: $profile->sellBannedUntil,
            vacationUntil: $profile->vacationUntil,
            level: $profile->level,
            points: $profile->points,
            leftPoints: $profile->leftPoints,
            percent: $profile->percent,
            referralsCount: $profile->referralsCount,
            inviterCode: $profile->inviterCode,
            referrerCode: $profile->referrerCode,
            referralTag: $profile->referralTag,
            countryCodeOfResidence: $profile->countryCodeOfResidence,
            countryCodeOfCurrentLocation: $profile->countryCodeOfCurrentLocation,
            isAchievementsEnabled: $profile->isAchievementsEnabled,
            isSkinTranslationEnabled: $profile->isSkinTranslationEnabled,
            isMcBattlePassEnabled: $profile->isMcBattlePassEnabled,
            tradingCompetitionStat: new TradingCompetitionStat($profile->tradingCompetitionStat->position, $profile->tradingCompetitionStat->topLevel),
        );
    }

    /**
     * The store of `store_my` and `store_edit` (one selection). `avatar` and `banner` arrive as plain strings and map to
     * the enums by value. A null `achievements` list maps to an empty one; a parameter that is null or of a union member
     * this SDK does not know is skipped (proposal P17).
     *
     * @param StoreSource $store
     */
    public function store(object $store): Store
    {
        return new Store(
            id: $store->id,
            slug: $store->slug,
            storeName: $store->storeName,
            avatar: $this->optionalWireValue(StoreAvatar::class, $store->avatar),
            banner: $this->optionalWireValue(StoreBanner::class, $store->banner),
            customAvatarUrl: $store->customAvatar,
            steamAvatar: $store->steamAvatar,
            deliveryPromise: $this->optionalEnum(DeliveryPromise::class, $store->deliveryPromise),
            isTopSeller: $store->isTopSeller,
            isKycVerified: $store->isKycVerified,
            isAchievementsPublic: $store->isAchievementsPublic,
            isFavoritesPublic: $store->isFavoritesPublic,
            isItemsOnSalePublic: $store->isItemsOnSalePublic,
            isStoreNamePublic: $store->isStoreNamePublic,
            isTradeHistoryPublic: $store->isTradeHistoryPublic,
            level: $store->level,
            points: $store->points,
            leftPoints: $store->leftPoints,
            years: $store->years,
            registeredAt: $store->registeredAt,
            achievements: array_map($this->achievement(...), $store->achievements ?? []),
        );
    }

    /**
     * @param AchievementSource $achievement
     */
    private function achievement(object $achievement): Achievement
    {
        return new Achievement(
            name: $this->enum(AchievementName::class, $achievement->name),
            points: $achievement->points,
            status: $this->enum(AchievementStatus::class, $achievement->status),
            kind: $this->enum(AchievementKind::class, $achievement->type),
            unlockedAt: $achievement->unlockedAt,
            params: $this->achievementParams($achievement->params),
        );
    }

    /**
     * @param list<mixed>|null $params
     *
     * @return list<AchievementParam>
     */
    private function achievementParams(?array $params): array
    {
        $mapped = [];
        foreach ($params ?? [] as $param) {
            if ($this->isBooleanParam($param)) {
                $mapped[] = new AchievementParam($this->enum(AchievementCondition::class, $param->name), $param->booleanValue);
            } elseif ($this->isStringParam($param)) {
                $mapped[] = new AchievementParam($this->enum(AchievementCondition::class, $param->name), $param->stringValue);
            }
        }

        return $mapped;
    }

    /**
     * @phpstan-assert-if-true object{name: WireEnum, booleanValue: bool} $value
     */
    private function isBooleanParam(mixed $value): bool
    {
        return $this->hasProperties($value, 'name', 'booleanValue');
    }

    /**
     * @phpstan-assert-if-true object{name: WireEnum, stringValue: string} $value
     */
    private function isStringParam(mixed $value): bool
    {
        return $this->hasProperties($value, 'name', 'stringValue');
    }

    public function personSummary(PersonPublicFields $person): PersonSummary
    {
        return new PersonSummary(
            id: $person->id,
            steamId: $person->steamId,
            steamName: $person->steamName,
            steamAvatar: $person->steamAvatar,
            steamLevel: $person->steamLevel,
            steamRegisteredAt: $person->steamRegisteredAt,
            deliveryPromise: $this->optionalEnum(DeliveryPromise::class, $person->deliveryPromise),
        );
    }

    /**
     * The one loop over a connection: a null edge or node is skipped, so is a node `$mapNode` maps to null (a type
     * this SDK does not know); a missing `pageInfo` means no next page.
     *
     * @template TNode of object
     * @template TItem of object
     *
     * @param object{edges: ?list<?object{node: ?TNode}>, pageInfo: ?PageInfoSource, totalCount: ?int} $connection
     * @param \Closure(TNode, string): (TItem|null) $mapNode argument: the node and its JSON path
     *
     * @return Page<TItem>
     *
     * @throws DecodeException
     */
    private function page(object $connection, string $path, \Closure $mapNode): Page
    {
        $items = [];
        foreach ($connection->edges ?? [] as $i => $edge) {
            if ($edge?->node !== null) {
                $item = $mapNode($edge->node, \sprintf('%s.edges[%d].node', $path, $i));
                if ($item !== null) {
                    $items[] = $item;
                }
            }
        }

        return new Page($items, $connection->pageInfo?->endCursor, $connection->pageInfo->hasNextPage ?? false, $connection->totalCount);
    }

    /**
     * A null entry in the list carries no sticker and is skipped (the schema allows it).
     *
     * @param StickerSuggestionsSource $result
     */
    public function stickerSuggestions(object $result): StickerSuggestions
    {
        $items = [];
        foreach ($result->suggestions ?? [] as $suggestion) {
            if ($suggestion !== null) {
                $items[] = new StickerSuggestion(
                    $suggestion->name,
                    $suggestion->title,
                    $suggestion->icon,
                    $this->optionalEnum(StickerFilm::class, $suggestion->film),
                    $suggestion->total,
                );
            }
        }

        return new StickerSuggestions($items, $result->moreCount);
    }

    /**
     * @param ProductHistorySource $product
     *
     * @throws DecodeException
     */
    public function productHistory(object $product, string $path): ProductHistory
    {
        return new ProductHistory(
            id: $product->id,
            status: $this->enum(ProductStatus::class, $product->status),
            price: $this->money($product->price, $path . '.price'),
            archivedAt: $product->archivedAt,
            deliveryType: $this->enum(DeliveryType::class, $product->deliveryType),
            assetId: $product->assetId,
            classId: $product->classId,
            instanceId: $product->instanceId,
            item: $this->inventoryItem($product->params, $product->description, $path . '.params'),
        );
    }

    /**
     * The `product` of a batch result (`MarketProductResult`), a union of a live listing (`MarketProduct`) and an archived
     * one (`MarketProductHistory`). The two are told apart by their fields; null (no product, or a type the server added
     * later) maps to null.
     *
     * @throws DecodeException
     */
    public function batchProduct(mixed $product, string $path): Product|ProductHistory|null
    {
        if ($this->isProductHistory($product)) {
            return $this->productHistory($product, $path);
        }

        return $this->isProduct($product) ? $this->product($product, $path) : null;
    }

    /**
     * A deal in progress (`MarketDeal`).
     *
     * @param DealSource $deal
     *
     * @throws DecodeException
     */
    public function deal(object $deal, string $path): Deal
    {
        return new Deal(
            id: $deal->id,
            customId: $deal->customId,
            status: $this->enum(DealStatus::class, $deal->status),
            price: $this->money($deal->price, $path . '.price'),
            createdAt: $deal->createdAt,
            deliveryType: $this->enum(DeliveryType::class, $deal->deliveryType),
            steamId: $deal->steamId,
            tradeId: $deal->tradeId,
            isTradeProtected: $deal->isTradeProtected,
            settlementTimeAt: $deal->settlementTimeAt,
            expiresAt: $deal->expiredAt,
            isCancelByBuyerSupported: $deal->isCancelByBuyerSupported,
            cancelByBuyerNeedWait: $deal->cancelByBuyerNeedWait,
            cancelReason: null,
            archivedAt: null,
            reversePenalty: null,
            reverseRefund: null,
            product: $this->productHistory($deal->product, $path . '.product'),
            item: $this->inventoryItem($deal->item, null, $path . '.item'),
            receiverTradeUrl: $this->optionalSecret($deal->receiverTradeUrl),
        );
    }

    /**
     * An archived deal (`MarketDealHistory`).
     *
     * @param DealHistorySource $deal
     *
     * @throws DecodeException
     */
    public function archivedDeal(object $deal, string $path): Deal
    {
        return new Deal(
            id: $deal->id,
            customId: $deal->customId,
            status: $this->enum(DealStatus::class, $deal->status),
            price: $this->money($deal->price, $path . '.price'),
            createdAt: $deal->createdAt,
            deliveryType: $this->enum(DeliveryType::class, $deal->deliveryType),
            steamId: $deal->steamId,
            tradeId: null,
            isTradeProtected: null,
            settlementTimeAt: null,
            expiresAt: null,
            isCancelByBuyerSupported: false,
            cancelByBuyerNeedWait: null,
            cancelReason: $this->optionalEnum(DealCancelReason::class, $deal->cancelReason),
            archivedAt: $deal->archivedAt,
            reversePenalty: $this->optionalMoney($deal->reversePenaltyValue, $path . '.reversePenaltyValue'),
            reverseRefund: $this->optionalMoney($deal->reverseRefundValue, $path . '.reverseRefundValue'),
            product: $this->productHistory($deal->product, $path . '.product'),
            item: null,
        );
    }

    /**
     * Null when `$item` is not a CS2 inventory item (null, or a type this SDK does not know). The Steam description is
     * the item's own when its selection has a known one, otherwise `$description` (a product history describes its
     * item on the product); stickers come only from the item's own selection.
     *
     * @throws DecodeException
     */
    private function inventoryItem(mixed $item, mixed $description, string $path): ?InventoryItem
    {
        // Read before the shape check: PHPStan treats an object shape as closed, so after it these look absent.
        $own = $this->hasDescription($item) ? $item->description : null;
        $stickers = $this->hasStickers($item) ? $item->stickers ?? [] : [];
        if (!$this->isInventoryItem($item)) {
            return null;
        }
        $steam = $this->isSteamItem($own) ? $own : ($this->isSteamItem($description) ? $description : null);

        return new InventoryItem(
            id: $item->id,
            assetId: $item->assetId,
            classId: $item->classId,
            instanceId: $item->instanceId,
            nameHash: $item->nameHash,
            name: $steam?->name,
            float: $item->float === null ? null : $this->decimal($item->float, $path . '.float'),
            paintSeed: $item->paintSeed,
            paintIndex: $item->paintIndex,
            phase: $item->phase,
            inspectLink: $item->link,
            isStatTrak: $steam?->isStatTrak,
            isSouvenir: $steam?->isSouvenir,
            stickers: $this->stickers($stickers, $path . '.stickers'),
        );
    }

    /**
     * A null entry in the list carries no sticker and is skipped (the schema allows it).
     *
     * @param list<CSGOStickerFields|null> $stickers
     *
     * @return list<Sticker>
     *
     * @throws DecodeException
     */
    private function stickers(array $stickers, string $path): array
    {
        $mapped = [];
        foreach ($stickers as $i => $sticker) {
            if ($sticker !== null) {
                $wear = $sticker->wear === null ? null : $this->decimal($sticker->wear, \sprintf('%s[%d].wear', $path, $i));
                $mapped[] = new Sticker($sticker->name, $sticker->title, $wear);
            }
        }

        return $mapped;
    }

    /**
     * Null or empty means none is set (proposal P16 point 1); anything else is wrapped in a {@see Secret}.
     */
    public function optionalSecret(#[\SensitiveParameter] ?string $value): ?Secret
    {
        return $value === null || $value === '' ? null : new Secret($value);
    }

    private function optionalMoney(?MoneyFields $money, string $path): ?Money
    {
        return $money instanceof MoneyFields ? $this->money($money, $path) : null;
    }

    /**
     * @throws DecodeException
     */
    private function decimal(string $value, string $path): Decimal
    {
        // `Decimal::of()` would chain an exception whose message holds the raw server value (an amount) into the logs.
        return Decimal::tryOf($value) ?? throw new DecodeException($path, 'not a decimal number');
    }

    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $enum
     *
     * @return E|UnknownEnumValue
     */
    private function enum(string $enum, \BackedEnum|UnknownEnumValue $value): \BackedEnum|UnknownEnumValue
    {
        return $this->wireValue($enum, (string) $value->value);
    }

    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $enum
     *
     * @return E|UnknownEnumValue
     */
    private function wireValue(string $enum, string $wire): \BackedEnum|UnknownEnumValue
    {
        return $enum::tryFrom($wire) ?? new UnknownEnumValue($enum, $wire);
    }

    /**
     * For a field the schema types as a plain String but whose values are those of `$enum`.
     *
     * @template E of \BackedEnum
     *
     * @param class-string<E> $enum
     *
     * @return E|UnknownEnumValue|null
     */
    private function optionalWireValue(string $enum, ?string $wire): \BackedEnum|UnknownEnumValue|null
    {
        return $wire === null ? null : $this->wireValue($enum, $wire);
    }

    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $enum
     *
     * @return E|UnknownEnumValue|null
     */
    private function optionalEnum(string $enum, \BackedEnum|UnknownEnumValue|null $value): \BackedEnum|UnknownEnumValue|null
    {
        return $value === null ? null : $this->enum($enum, $value);
    }

    /**
     * @phpstan-assert-if-true ItemSource $value
     */
    private function isInventoryItem(mixed $value): bool
    {
        return $this->hasProperties($value, 'id', 'assetId', 'classId', 'instanceId', 'nameHash', 'float', 'paintSeed', 'paintIndex', 'phase', 'link');
    }

    /**
     * @phpstan-assert-if-true ProductSource $value
     */
    private function isProduct(mixed $value): bool
    {
        return $this->hasProperties($value, 'id', 'slug', 'price', 'createdAt', 'isOwner', 'deliveryType', 'deliveryPromise', 'item');
    }

    /**
     * @phpstan-assert-if-true ProductHistorySource $value
     */
    private function isProductHistory(mixed $value): bool
    {
        return $this->hasProperties($value, 'id', 'status', 'price', 'archivedAt', 'deliveryType', 'assetId', 'classId', 'instanceId', 'description', 'params');
    }

    /**
     * @phpstan-assert-if-true SteamItemSource $value
     */
    private function isSteamItem(mixed $value): bool
    {
        return $this->hasProperties($value, 'name', 'isStatTrak', 'isSouvenir');
    }

    /**
     * @phpstan-assert-if-true object{description: mixed} $value
     */
    private function hasDescription(mixed $value): bool
    {
        return $this->hasProperties($value, 'description');
    }

    /**
     * @phpstan-assert-if-true object{stickers: list<CSGOStickerFields|null>|null} $value
     */
    private function hasStickers(mixed $value): bool
    {
        return $this->hasProperties($value, 'stickers');
    }

    /**
     * Generated classes declare every selected field as a typed public property, so a property name of the
     * selection implies its type.
     */
    private function hasProperties(mixed $value, string ...$names): bool
    {
        if (!\is_object($value)) {
            return false;
        }
        foreach ($names as $name) {
            if (!property_exists($value, $name)) {
                return false;
            }
        }

        return true;
    }
}
