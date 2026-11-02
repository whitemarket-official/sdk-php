<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Selling;

use WhiteMarket\Sdk\Errors\BatchIncompleteException;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\MutationRejection;
use WhiteMarket\Sdk\Errors\WhiteMarketException;
use WhiteMarket\Sdk\Generated\Enum as Wire;
use WhiteMarket\Sdk\Generated\GeneratedClient;
use WhiteMarket\Sdk\Generated\Input\MarketProductEditInput;
use WhiteMarket\Sdk\Generated\Input\MarketProductNewInput;
use WhiteMarket\Sdk\Generated\Input\MarketProductSearchInput;
use WhiteMarket\Sdk\Market\ProductFilter;
use WhiteMarket\Sdk\Models\InventoryItem;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\Page;
use WhiteMarket\Sdk\Models\PageRequest;
use WhiteMarket\Sdk\Models\Paginator;
use WhiteMarket\Sdk\Models\Product;
use WhiteMarket\Sdk\Models\ProductHistory;
use WhiteMarket\Sdk\Models\WireEncoder;

/**
 * `$client->selling` (SDK_SPEC 3.5): list items for sale, change prices, take listings down, read your own listings.
 *
 * Every mutation is sent at most once (CLAUDE.md rule 2): the transport never retries it and no method resends it. The
 * selling mutations have no idempotency key, so there is no reconciliation either: when one fails in a way that does not
 * tell whether it ran (a timeout, a 5xx, an unreadable response), the exception propagates as is and the outcome is
 * unknown; check {@see myProducts()} before trying again. A malformed request, a rejected credential and a rate limit mean
 * the server did not run it, and so does a GraphQL error of the root field of a single mutation. In a `*Many` call only
 * a GraphQL request error without a path (parse, validation) does: an error at the root field means the resolver started
 * and may have acted on some items.
 *
 * A `*Many` method sends one request per chunk of `$batchSize` items (CLAUDE.md rules 6 and 7), in order, and stops at
 * the first request that fails. It returns only when the server reported an outcome for every item; otherwise it throws
 * {@see BatchIncompleteException} with the outcome of every item (P12).
 *
 * No upper bound is enforced on `$batchSize`: the backend's maximum batch size is unconfirmed (P12 point 4); a value
 * above it makes the server reject the chunk.
 *
 * @phpstan-type BatchRow object{index: int, isCreated: bool, product: mixed, errorCategory: ?string, errorMessage: ?string}
 */
final readonly class SellingApi
{
    /** Items per request of a `*Many` method (SDK_SPEC 3.5: "default 100, confirm with backend"). */
    public const DEFAULT_BATCH_SIZE = 100;

    /** Shortest lifetime in seconds {@see setLifetimeForAll()} accepts (SDK_SPEC 3.5, WHITEMARKET_API.md 4). */
    public const MIN_LIFETIME_FOR_ALL = 600;

    /**
     * @param int<1, max> $batchSize items per request of a `*Many` method
     *
     * @throws \InvalidArgumentException `$batchSize` is below 1
     *
     * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient}
     */
    public function __construct(
        private GeneratedClient $api,
        private ModelMapper $mapper,
        private int $batchSize = self::DEFAULT_BATCH_SIZE,
        private WireEncoder $wire = new WireEncoder(),
        private MutationRejection $rejection = new MutationRejection(),
    ) {
        if ($batchSize < 1) {
            throw new \InvalidArgumentException('The batch size must be at least 1.');
        }
    }

    /**
     * Puts one item on sale (`market_new`). Sent once, never retried; the outcome of an ambiguous failure is unknown:
     * check {@see myProducts()}.
     *
     * @param string $item inventory id (UUID) or 64-bit Steam asset id (digits, kept as a string)
     * @param ?ListingOptions $options `Semi` delivery and the server's defaults when null
     *
     * @throws \InvalidArgumentException `$item` is neither an inventory id nor an asset id, or the currency of `$price` is unknown
     * @throws WhiteMarketException
     */
    public function list(string $item, Money $price, ?ListingOptions $options = null): Product
    {
        $reference = ItemReference::inventoryOrAsset($item);
        $priceInput = $this->wire->money($price);
        $options ??= new ListingOptions();

        return $this->mapper->product(
            $this->api->marketNew(
                price: $priceInput,
                assetId: $reference['assetId'],
                deliveryPromise: $this->wire->enum(Wire\DeliveryPromise::class, $options->deliveryPromise),
                deliveryType: $this->wire->enum(Wire\DeliveryType::class, $options->deliveryType),
                inventoryId: $reference['inventoryId'],
                lifetime: $options->lifetime,
            ),
            'market_new',
        );
    }

    /**
     * Puts many items on sale (`market_new_multiple`), `$options` applying to every item. One request per chunk, each
     * sent once. Returns one {@see BatchItemResult} per listing, in the caller's order, each `Succeeded` or `Failed`
     * as the server reported; an empty list sends nothing.
     *
     * @param list<Listing> $listings
     *
     * @return list<BatchItemResult>
     *
     * @throws BatchIncompleteException a request failed (its items are `Unknown`, or `NotSent` when the server rejected
     *                                  it before running it: HTTP 400/401/429 or a GraphQL error without a path; later items are `NotSent`, earlier ones keep their
     *                                  outcome), or the server did not report some items (`Unknown`)
     */
    public function listMany(array $listings, ?ListingOptions $options = null): array
    {
        $options ??= new ListingOptions();
        $promise = $this->wire->enum(Wire\DeliveryPromise::class, $options->deliveryPromise);
        $type = $this->wire->enum(Wire\DeliveryType::class, $options->deliveryType);

        $inputs = [];
        foreach ($listings as $listing) {
            $reference = ItemReference::inventoryOrAsset($listing->item);
            $inputs[] = new MarketProductNewInput($this->wire->money($listing->price), $reference['assetId'], $reference['inventoryId']);
        }

        return $this->batch(
            $inputs,
            'market_new_multiple',
            fn(array $chunk): array => $this->api->marketNewMultiple(items: $chunk, deliveryPromise: $promise, deliveryType: $type, lifetime: $options->lifetime),
            static fn(MarketProductNewInput $input, Product|ProductHistory $product): bool => !$product instanceof Product || !$product->item instanceof InventoryItem
                || ($input->assetId !== null ? $product->item->assetId === $input->assetId : self::sameUuid($input->inventoryId, $product->item->id)),
        );
    }

    /**
     * Changes the price of one listing (`market_edit`). Sent once, never retried; the outcome of an ambiguous failure
     * is unknown: check {@see myProducts()}.
     *
     * @throws \InvalidArgumentException `$productId` is not a UUID, or the currency of `$price` is unknown
     * @throws WhiteMarketException
     */
    public function reprice(string $productId, Money $price): Product
    {
        ItemReference::assertProductId($productId);

        return $this->mapper->product($this->api->marketEdit($productId, $this->wire->money($price)), 'market_edit');
    }

    /**
     * Changes the prices of many listings (`market_edit_multiple`); the batch rules of {@see listMany()} apply.
     *
     * @param list<PriceChange> $changes
     *
     * @return list<BatchItemResult>
     *
     * @throws BatchIncompleteException see {@see listMany()}
     */
    public function repriceMany(array $changes): array
    {
        $inputs = [];
        foreach ($changes as $change) {
            $reference = ItemReference::productOrAsset($change->product);
            $inputs[] = new MarketProductEditInput($this->wire->money($change->price), $reference['assetId'], $reference['productId']);
        }

        return $this->batch(
            $inputs,
            'market_edit_multiple',
            fn(array $chunk): array => $this->api->marketEditMultiple($chunk),
            static fn(MarketProductEditInput $input, Product|ProductHistory $product): bool => !$product instanceof Product
                || ($input->productId !== null ? self::sameUuid($input->productId, $product->id) : (!$product->item instanceof InventoryItem || $product->item->assetId === $input->assetId)),
        );
    }

    /**
     * Takes one listing down (`market_remove`) and returns its archived record. Sent once, never retried; the outcome
     * of an ambiguous failure is unknown: check {@see myProducts()}.
     *
     * @throws \InvalidArgumentException `$productId` is not a UUID
     * @throws WhiteMarketException
     */
    public function remove(string $productId): ProductHistory
    {
        ItemReference::assertProductId($productId);

        return $this->mapper->productHistory($this->api->marketRemove($productId), 'market_remove');
    }

    /**
     * Takes many listings down (`market_remove_multiple`); the batch rules of {@see listMany()} apply. Every id is
     * checked before the first request.
     *
     * @param list<string> $productIds listing ids (UUIDs)
     *
     * @return list<BatchItemResult>
     *
     * @throws \InvalidArgumentException an id is not a UUID; nothing is sent
     * @throws BatchIncompleteException see {@see listMany()}
     */
    public function removeMany(array $productIds): array
    {
        foreach ($productIds as $productId) {
            ItemReference::assertProductId($productId);
        }

        return $this->batch(
            $productIds,
            'market_remove_multiple',
            fn(array $chunk): array => $this->api->marketRemoveMultiple($chunk),
            static fn(string $productId, Product|ProductHistory $product): bool => !$product instanceof ProductHistory || self::sameUuid($productId, $product->id),
        );
    }

    /**
     * Sets the lifetime of every active listing (`market_lifetime_all`), in seconds. Sent once, never retried.
     *
     * @return bool the server's answer
     *
     * @throws \InvalidArgumentException `$seconds` is below {@see MIN_LIFETIME_FOR_ALL}
     * @throws WhiteMarketException
     */
    public function setLifetimeForAll(int $seconds): bool
    {
        if ($seconds < self::MIN_LIFETIME_FOR_ALL) {
            throw new \InvalidArgumentException(\sprintf('The lifetime must be at least %d seconds, got %d.', self::MIN_LIFETIME_FOR_ALL, $seconds));
        }

        return $this->api->marketLifetimeAll($seconds);
    }

    /**
     * One page of your own listings (`market_my`). Pass `new PageRequest(after: $previous->endCursor)` for the next page
     * while `$previous->hasNextPage` is true, or use {@see iterateMyProducts()}.
     *
     * @param ProductFilter|MarketProductSearchInput|null $filter a raw `MarketProductSearchInput` is sent as is
     *
     * @return Page<Product>
     *
     * @throws WhiteMarketException
     */
    public function myProducts(ProductFilter|MarketProductSearchInput|null $filter = null, ?PageRequest $page = null): Page
    {
        $result = $this->api->marketMy(
            $page?->toInput(),
            $filter instanceof ProductFilter ? $filter->toInput() : $filter,
        );

        return $this->mapper->productPage($result, 'market_my');
    }

    /**
     * Every listing of yours that matches, page after page; a page is requested only when the previous one is consumed.
     *
     * @param ?int $pageSize items per request; the server's default when null
     *
     * @return \Generator<int, Product>
     *
     * @throws WhiteMarketException
     */
    public function iterateMyProducts(ProductFilter|MarketProductSearchInput|null $filter = null, ?int $pageSize = null): \Generator
    {
        return (new Paginator())->iterate(fn(?string $after): Page => $this->myProducts($filter, new PageRequest($pageSize, $after)), 'market_my');
    }

    /**
     * The one chunk loop of the three `*Many` methods. Every chunk is one mutation request, sent once. A chunk that
     * fails marks its items `NotSent` (rejection: HTTP 400/401/429, or a GraphQL error without a path) or `Unknown`, and the batch stops: later chunks stay `NotSent`. A row
     * of the answer is matched to its item by `index`, read as the 0-based position within the chunk (P12; the schema
     * says "Position in request", not confirmed by the backend). An answer whose `index` is out of range or repeated
     * cannot be matched, and the mutation has already run: the chunk is `Unknown`. An item without a row is `Unknown`
     * and the later chunks still go out; the exception comes at the end. A product that cannot be mapped does not
     * change the outcome the server reported, it only leaves `product` null.
     *
     * A row whose product carries the identity of an item is also checked against the item requested at that position
     * (`$sameItem`; a row without a product, or a product without the identity, passes): `index` is unconfirmed, and a
     * server counting from 1 that answers only some rows would otherwise attach rows to the wrong items. A mismatch
     * makes the whole chunk `Unknown` like an unusable `index`.
     *
     * @template T
     *
     * @param list<T> $items
     * @param string $field root field of the mutation
     * @param \Closure(list<T>): list<BatchRow> $send the single request of one chunk
     * @param \Closure(T, Product|ProductHistory): bool $sameItem whether the returned product is the one requested
     *
     * @return list<BatchItemResult> every item `Succeeded` or `Failed`
     *
     * @throws BatchIncompleteException
     */
    private function batch(array $items, string $field, \Closure $send, \Closure $sameItem): array
    {
        $results = $this->withOutcome([], 0, \count($items), BatchOutcome::NotSent);

        foreach (array_chunk($items, $this->batchSize) as $k => $chunk) {
            $offset = $k * $this->batchSize;
            $size = \count($chunk);
            try {
                $rows = $send($chunk);
            } catch (\Throwable $e) {
                $outcome = $this->rejection->isRejection($e, $field, rootFieldErrorRejects: false) ? BatchOutcome::NotSent : BatchOutcome::Unknown;

                throw new BatchIncompleteException($this->withOutcome($results, $offset, $size, $outcome), $e);
            }
            $chunkResults = [];
            try {
                $byPosition = $this->byPosition($rows, $size, $field);
                foreach ($chunk as $position => $item) {
                    $row = $byPosition[$position] ?? null;
                    $chunkResults[$offset + $position] = $row === null
                        ? new BatchItemResult($offset + $position, BatchOutcome::Unknown)
                        : $this->resultFromRow($offset + $position, $row, \sprintf('%s[%d]', $field, $position), static fn(Product|ProductHistory $product): bool => $sameItem($item, $product));
                }
            } catch (DecodeException $e) {
                // The mutation ran: only the answer is unusable.
                throw new BatchIncompleteException($this->withOutcome($results, $offset, $size, BatchOutcome::Unknown), $e);
            }
            $results = array_replace($results, $chunkResults);
        }

        foreach ($results as $result) {
            if ($result->outcome === BatchOutcome::Unknown) {
                throw new BatchIncompleteException(array_values($results));
            }
        }

        return array_values($results);
    }

    /**
     * @param list<BatchRow> $rows
     *
     * @return array<int, BatchRow> by position in the chunk
     *
     * @throws DecodeException an `index` outside `0..$size - 1`, or used twice
     */
    private function byPosition(array $rows, int $size, string $field): array
    {
        $byPosition = [];
        foreach ($rows as $i => $row) {
            if ($row->index < 0 || $row->index >= $size) {
                throw new DecodeException(\sprintf('%s[%d].index', $field, $i), \sprintf('%d is outside the %d items sent', $row->index, $size));
            }
            if (isset($byPosition[$row->index])) {
                throw new DecodeException(\sprintf('%s[%d].index', $field, $i), \sprintf('%d is reported twice', $row->index));
            }
            $byPosition[$row->index] = $row;
        }

        return $byPosition;
    }

    /**
     * @param BatchRow $row
     * @param \Closure(Product|ProductHistory): bool $isRequestedItem
     *
     * @throws DecodeException the product is not the item requested at this position
     */
    private function resultFromRow(int $index, object $row, string $path, \Closure $isRequestedItem): BatchItemResult
    {
        try {
            $product = $this->mapper->batchProduct($row->product, $path . '.product');
        } catch (DecodeException) {
            // The outcome the server reported stands; only its product is unreadable.
            $product = null;
        }
        if ($product !== null && !$isRequestedItem($product)) {
            throw new DecodeException($path . '.product.id', 'the product is not the item requested at this position');
        }

        return new BatchItemResult($index, $row->isCreated ? BatchOutcome::Succeeded : BatchOutcome::Failed, $product, $row->errorCategory, $row->errorMessage);
    }

    private static function sameUuid(?string $requested, string $returned): bool
    {
        return $requested !== null && strcasecmp($requested, $returned) === 0;
    }

    /**
     * @param array<int, BatchItemResult> $results
     *
     * @return list<BatchItemResult>
     */
    private function withOutcome(array $results, int $offset, int $size, BatchOutcome $outcome): array
    {
        for ($i = $offset; $i < $offset + $size; ++$i) {
            $results[$i] = new BatchItemResult($i, $outcome);
        }

        return array_values($results);
    }
}
