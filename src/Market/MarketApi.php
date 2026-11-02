<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Market;

use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\MarketProductSearchInput;
use WhiteMarket\Sdk\Generated\Input\SteamLinkInput;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Page;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\Paginator;
use WhiteMarket\Sdk\Models\Product;
use WhiteMarket\Sdk\Models\StickerSuggestions;

/**
 * `$client->market` (SDK_SPEC 3.2): search the public market and look up one listing. Every method is a read-only
 * query, so the transport retries it on transient failures (SDK_SPEC 1.4).
 */
final readonly class MarketApi
{
    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private GeneratedClient $api,
        private ModelMapper $mapper,
    ) {}

    /**
     * One page of listings (`market_list`). Pass `new PageRequest(after: $previous->endCursor)` for the next page while
     * `$previous->hasNextPage` is true, or use {@see iterate()}.
     *
     * @param ProductFilter|MarketProductSearchInput|null $filter a raw `MarketProductSearchInput` is sent as is
     * @param ?PageRequest $page page size and cursor; the server's defaults when null
     *
     * @return Page<Product>
     *
     * @throws WhiteMarketException
     */
    public function search(ProductFilter|MarketProductSearchInput|null $filter = null, ?PageRequest $page = null): Page
    {
        $result = $this->api->marketList(
            $page?->toInput(),
            $filter instanceof ProductFilter ? $filter->toInput() : $filter,
        );

        return $this->mapper->productPage($result, 'market_list');
    }

    /**
     * Every listing that matches, page after page; a page is requested only when the previous one is consumed.
     * Keys are 0, 1, 2, ... across pages.
     *
     * @param ?int $pageSize items per request; the server's default when null
     *
     * @return \Generator<int, Product>
     *
     * @throws WhiteMarketException
     */
    public function iterate(ProductFilter|MarketProductSearchInput|null $filter = null, ?int $pageSize = null): \Generator
    {
        return (new Paginator())->iterate(fn(?string $after): Page => $this->search($filter, new PageRequest($pageSize, $after)), 'market_list');
    }

    /**
     * One listing by its id or slug (`market_item`).
     *
     * @throws WhiteMarketException {@see NotFoundException} when there is no such listing (category
     *                              `market.product.not_found`)
     */
    public function get(string $idOrSlug): Product
    {
        return $this->mapper->product($this->api->marketItem($idOrSlug), 'market_item');
    }

    /**
     * Sticker names that match `$search` (`market_csgo_stickers_suggestion`), to fill `ProductFilter::$stickerNames`.
     *
     * @param int $limit window size (the API default is 10)
     * @param int $offset window start (the API default is 0)
     *
     * @throws WhiteMarketException
     */
    public function stickerSuggestions(string $search, int $limit = 10, int $offset = 0): StickerSuggestions
    {
        return $this->mapper->stickerSuggestions($this->api->marketCsgoStickersSuggestion($search, $limit, $offset));
    }

    /**
     * Asks the API to check a Steam trade URL (`market_trade_url`). The schema documents only "Check passed trade
     * url"; the meaning of the returned string is not documented, so the SDK returns it unchanged. An invalid URL
     * surfaces as a GraphQL error (an exception), not as a return value.
     *
     * @throws WhiteMarketException
     */
    public function checkTradeUrl(#[\SensitiveParameter] string $url): string
    {
        return $this->api->marketTradeUrl(new SteamLinkInput($url));
    }
}
