<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Buying;

use WhiteMarket\Sdk\Errors\AuthenticationException;
use WhiteMarket\Sdk\Errors\BadRequestException;
use WhiteMarket\Sdk\Errors\GraphQLException;
use WhiteMarket\Sdk\Errors\MutationRejection;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\PurchaseOutcomeUnknownException;
use WhiteMarket\Sdk\Errors\RateLimitException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\MarketProductAppIdAssetIdInput;
use WhiteMarket\Sdk\Generated\Input\MarketProductAppIdNameHashInput;
use WhiteMarket\Sdk\Generated\Input\MoneyInput;
use WhiteMarket\Sdk\Generated\Input\SteamLinkInput;
use WhiteMarket\Sdk\Generated\Operation\Mutation\MarketBuy\Result;
use WhiteMarket\Sdk\Models\App;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\Phase;
use WhiteMarket\Sdk\Models\Uuid;
use WhiteMarket\Sdk\Models\WireEncoder;
use WhiteMarket\Sdk\Transport\Sleeper;
use WhiteMarket\Sdk\Transport\SystemSleeper;

/**
 * `$client->buying` (SDK_SPEC 3.3): buy a listing by id, the cheapest listing of an item, or an item by its asset id.
 *
 * `market_buy` is a mutation: it is sent once per call and never retried (CLAUDE.md rule 2). When it fails in a way
 * that does not tell whether the purchase happened, the SDK reconciles by `customId` instead of resending it: after
 * `BuyReconciliation::$delay` it looks the deal up (`deal_item`, then `deal_history_item`) and returns it with
 * `BuyResult::$reconciled` true, or throws {@see PurchaseOutcomeUnknownException}.
 */
final readonly class BuyingApi
{
    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private GeneratedClient $api,
        private ModelMapper $mapper,
        private BuyReconciliation $reconciliation,
        private Sleeper $sleeper = new SystemSleeper(),
        private WireEncoder $wire = new WireEncoder(),
        private MutationRejection $rejection = new MutationRejection(),
    ) {}

    /**
     * Buys the listing `$productId` (`market_buy` by id), optionally capped by `$maxPrice`: the server refuses the
     * purchase when the price is above it (`market.product.not_found_in_specified_price`, observed live, no deal is
     * created). The mutation is sent once and
     * never retried; on an ambiguous failure the SDK reconciles by customId (SDK_SPEC 3.3).
     *
     * @throws \InvalidArgumentException `$maxPrice` has a currency this SDK does not know
     * @throws WhiteMarketException the rejection, the deal was not created: {@see GraphQLException} (for example
     *                              insufficient funds; it means the server rejected the purchase before running it: an
     *                              error that came after the purchase ran is reconciled like any other ambiguous
     *                              failure), {@see BadRequestException}, {@see AuthenticationException},
     *                              {@see RateLimitException}; or {@see PurchaseOutcomeUnknownException}: the purchase failed
     *                              ambiguously and no deal was found with its customId
     */
    public function buyProduct(string $productId, ?Money $maxPrice = null, #[\SensitiveParameter] ?BuyOptions $options = null): BuyResult
    {
        $maxPriceInput = $this->maxPriceInput($maxPrice);
        $link = $this->link($options);

        return $this->buy($options, fn(string $customId): Result => $this->api->marketBuy(
            customId: $customId,
            id: $productId,
            link: $link,
            maxPriceInput: $maxPriceInput,
        ));
    }

    /**
     * Buys the cheapest listing of the item `$nameHash` (`market_buy` by app and name hash), optionally capped by
     * `$maxPrice`: the server refuses a dearer purchase with `market.product.not_found_in_specified_price` (observed
     * live for the id form). The mutation is sent once and never retried; on an ambiguous failure the SDK reconciles
     * by customId (SDK_SPEC 3.3).
     *
     * @throws \InvalidArgumentException `$maxPrice` has a currency this SDK does not know
     * @throws WhiteMarketException the rejection, the deal was not created: {@see GraphQLException} (for example
     *                              insufficient funds; it means the server rejected the purchase before running it: an
     *                              error that came after the purchase ran is reconciled like any other ambiguous
     *                              failure), {@see BadRequestException}, {@see AuthenticationException},
     *                              {@see RateLimitException}; or {@see PurchaseOutcomeUnknownException}: the purchase failed
     *                              ambiguously and no deal was found with its customId
     */
    public function buyCheapest(
        App $app,
        string $nameHash,
        ?Money $maxPrice = null,
        ?Phase $phase = null,
        ?DeliveryPromise $deliveryPromise = null,
        #[\SensitiveParameter]
        ?BuyOptions $options = null,
    ): BuyResult {
        $target = new MarketProductAppIdNameHashInput($this->wire->enum(Wire\SteamApp::class, $app), $nameHash, $this->wire->enum(Wire\CSGOPhase::class, $phase));
        $maxPriceInput = $this->maxPriceInput($maxPrice);
        $promise = $this->wire->enum(Wire\DeliveryPromise::class, $deliveryPromise);
        $link = $this->link($options);

        return $this->buy($options, fn(string $customId): Result => $this->api->marketBuy(
            appIdNameHashInput: $target,
            customId: $customId,
            deliveryPromise: $promise,
            link: $link,
            maxPriceInput: $maxPriceInput,
        ));
    }

    /**
     * Buys the listing of one inventory item by its Steam asset id (`market_buy` by app and asset id). The mutation is
     * sent once and never retried; on an ambiguous failure the SDK reconciles by customId (SDK_SPEC 3.3).
     *
     * `$maxPrice` caps the price: the server is expected to refuse the purchase when the price is above it (observed
     * live for the id form; not yet tried with an asset id).
     *
     * @param string $assetId 64-bit Steam asset id, kept as a string
     *
     * @throws \InvalidArgumentException `$maxPrice` has a currency this SDK does not know
     * @throws WhiteMarketException the rejection, the deal was not created: {@see GraphQLException} (for example
     *                              insufficient funds; it means the server rejected the purchase before running it: an
     *                              error that came after the purchase ran is reconciled like any other ambiguous
     *                              failure), {@see BadRequestException}, {@see AuthenticationException},
     *                              {@see RateLimitException}; or {@see PurchaseOutcomeUnknownException}: the purchase failed
     *                              ambiguously and no deal was found with its customId
     */
    public function buyByAssetId(App $app, string $assetId, ?Money $maxPrice = null, #[\SensitiveParameter] ?BuyOptions $options = null): BuyResult
    {
        $target = new MarketProductAppIdAssetIdInput($this->wire->enum(Wire\SteamApp::class, $app), $assetId);
        $maxPriceInput = $this->maxPriceInput($maxPrice);
        $link = $this->link($options);

        return $this->buy($options, fn(string $customId): Result => $this->api->marketBuy(
            appIdAssetIdInput: $target,
            customId: $customId,
            link: $link,
            maxPriceInput: $maxPriceInput,
        ));
    }

    /**
     * SDK_SPEC 3.3. A business rejection, a malformed request, a rejected credential and a rate limit all mean the
     * server did not run the purchase; every other failure may have happened after it ran.
     *
     * @param \Closure(string): Result $send the single `market_buy` call
     */
    private function buy(#[\SensitiveParameter] ?BuyOptions $options, #[\SensitiveParameter] \Closure $send): BuyResult
    {
        $customId = $options->customId ?? Uuid::v4();

        try {
            return new BuyResult($this->mapper->deal($send($customId), 'market_buy'), $customId, false);
        } catch (\Throwable $e) {
            // Anything but a rejection, WhiteMarketException or not (a broken collaborator): the outcome is unknown, never a clean error.
            if ($this->rejection->isRejection($e, 'market_buy')) {
                throw $e;
            }

            return $this->reconcile($customId, $e);
        }
    }

    /**
     * The cause is sensitive: its trace holds the caller's frames, and with them the trade URL.
     *
     * @throws PurchaseOutcomeUnknownException
     */
    private function reconcile(string $customId, #[\SensitiveParameter] \Throwable $cause): BuyResult
    {
        $this->sleeper->sleep($this->reconciliation->delay);
        $lastError = null;
        for ($attempt = 0; $attempt < $this->reconciliation->attempts; ++$attempt) {
            try {
                return new BuyResult($this->mapper->deal($this->api->dealItem(customId: $customId), 'deal_item'), $customId, true);
            } catch (\Throwable $e) {
                // Not there (yet), or the lookup failed: either way the archive is looked at next, and the outcome stays unknown.
                $lastError = $this->moreTelling($lastError, $e);
            }
            try {
                return new BuyResult($this->mapper->archivedDeal($this->api->dealHistoryItem(customId: $customId), 'deal_history_item'), $customId, true);
            } catch (\Throwable $e) {
                $lastError = $this->moreTelling($lastError, $e);
            }
            $this->sleeper->sleep($this->reconciliation->interval);
        }

        throw new PurchaseOutcomeUnknownException($customId, $cause, $lastError);
    }

    /**
     * The lookup error to report: the most recent one that is not a plain "not found" (a failed lookup says more than an
     * absent deal), or the most recent not-found when every lookup was one.
     */
    private function moreTelling(?\Throwable $current, \Throwable $new): \Throwable
    {
        return !$new instanceof NotFoundException || !$current instanceof \Throwable || $current instanceof NotFoundException ? $new : $current;
    }

    /**
     * @throws \InvalidArgumentException the currency is not one this SDK knows
     */
    private function maxPriceInput(?Money $maxPrice): ?MoneyInput
    {
        return $maxPrice instanceof Money ? $this->wire->money($maxPrice) : null;
    }

    private function link(#[\SensitiveParameter] ?BuyOptions $options): ?SteamLinkInput
    {
        return $options?->tradeUrl === null ? null : new SteamLinkInput($options->tradeUrl);
    }
}
