<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Store;

use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Models\ModelMapper;

/**
 * `$client->store` (SDK_SPEC 3.9): the partner's own store.
 *
 * {@see get()} is a query, so the transport retries it on transient failures (SDK_SPEC 1.4). {@see edit()} is a
 * mutation: it is sent exactly once and never retried (CLAUDE.md rule 2), and a failure propagates as it is. It is
 * idempotent in effect (it sets values), so the caller may repeat it after checking the state with {@see get()}.
 */
final readonly class StoreApi
{
    /**
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private GeneratedClient $api,
        private ModelMapper $mapper,
    ) {}

    /**
     * The store (`store_my`).
     *
     * @throws WhiteMarketException
     */
    public function get(): Store
    {
        return $this->mapper->store($this->api->storeMy());
    }

    /**
     * Changes the store settings that are given (`store_edit`) and returns the store as the server now has it.
     *
     * @throws WhiteMarketException
     * @throws \InvalidArgumentException an avatar or banner the schema does not accept
     */
    public function edit(StoreSettings $settings): Store
    {
        return $this->mapper->store($this->api->storeEdit($settings->toInput()));
    }
}
