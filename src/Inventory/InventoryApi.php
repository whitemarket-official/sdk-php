<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Inventory;

use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\PersonInventorySearchInput;
use WhiteMarket\Sdk\Models\InventoryItem;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Page;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\Paginator;

/**
 * `$client->inventory` (SDK_SPEC 3.6): the partner's own inventory. Listing is a read-only query, so the transport
 * retries it on transient failures (SDK_SPEC 1.4); {@see refreshFromSteam()} is a mutation and is never retried.
 */
final readonly class InventoryApi
{
    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private GeneratedClient $api,
        private ModelMapper $mapper,
    ) {}

    /**
     * One page of the inventory (`inventory_my`). Pass `new PageRequest(after: $previous->endCursor)` for the next page
     * while `$previous->hasNextPage` is true, or use {@see iterate()}. Items of a game other than CS2 are skipped,
     * so `Page::$totalCount` (the server's count) includes them and can exceed the items returned.
     *
     * @param InventoryFilter|PersonInventorySearchInput|null $filter a raw `PersonInventorySearchInput` is sent as is
     * @param ?PageRequest $page page size and cursor; the server's defaults when null
     *
     * @return Page<InventoryItem>
     *
     * @throws WhiteMarketException
     */
    public function list(InventoryFilter|PersonInventorySearchInput|null $filter = null, ?PageRequest $page = null): Page
    {
        $result = $this->api->inventoryMy(
            $page?->toInput(),
            $filter instanceof InventoryFilter ? $filter->toInput() : $filter,
        );

        return $this->mapper->inventoryPage($result, 'inventory_my');
    }

    /**
     * Every inventory item that matches, page after page; a page is requested only when the previous one is consumed.
     * Keys are 0, 1, 2, ... across pages.
     *
     * @param ?int $pageSize items per request; the server's default when null
     *
     * @return \Generator<int, InventoryItem>
     *
     * @throws WhiteMarketException
     */
    public function iterate(InventoryFilter|PersonInventorySearchInput|null $filter = null, ?int $pageSize = null): \Generator
    {
        return (new Paginator())->iterate(fn(?string $after): Page => $this->list($filter, new PageRequest($pageSize, $after)), 'inventory_my');
    }

    /**
     * Asks the server to reload the inventory from Steam (`inventory_update`). A mutation: sent exactly once and never
     * retried, so after an ambiguous failure (5xx, network error, unreadable response) the exception propagates and the
     * outcome is unknown. Repeating a refresh is harmless, so the caller may call this method again; the SDK never does.
     *
     * @throws WhiteMarketException
     */
    public function refreshFromSteam(): void
    {
        $this->api->inventoryUpdate();
    }
}
