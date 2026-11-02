# WhiteMarket PHP SDK

[![CI](https://github.com/whitemarket-official/sdk-php/actions/workflows/ci.yml/badge.svg)](https://github.com/whitemarket-official/sdk-php/actions/workflows/ci.yml)
[![Packagist version](https://img.shields.io/packagist/v/whitemarket/sdk)](https://packagist.org/packages/whitemarket/sdk)
[![License: MIT](https://img.shields.io/github/license/whitemarket-official/sdk-php)](LICENSE)

> Status: in development, not released yet. The public API may change until `1.0.0`.

Official PHP SDK for the [WhiteMarket](https://white.market) partner API (CS2 skins marketplace).

- Composer package `whitemarket/sdk` (Packagist), root namespace `WhiteMarket\Sdk`
- Runtime support: PHP 8.2+
- License: MIT

## What it is

Two layers:

- **Generated layer** (`WhiteMarket\Sdk\Generated`): typed client for every operation of the partner GraphQL schema
  (`https://api.white.market/graphql/partner`), regenerated when the schema changes. Never edited by hand.
- **High-level client** (`WhiteMarketClient`): authentication with a Partner Token (cached access token, automatic refresh),
  market search, buying with an idempotent `customId` and outcome reconciliation, deal tracking, selling, inventory, wallet,
  profile, store, and the public export files (`ExportClient`).

Not available yet (the API is not in this SDK, do not look for it):

| Feature | Status |
|---|---|
| Buy orders | not available yet (P14) |

## Install

```bash
composer require whitemarket/sdk
```

The default HTTP client is Guzzle; any PSR-18 client can be injected (see
[Injected HTTP client](#injected-http-client-and-its-trust-boundary)).

## Requirements

- PHP 8.2 or newer.
- Extensions `ext-curl` and `ext-zlib` (both required by `composer.json`; `ext-json` ships with PHP 8).
- Runtime packages installed by Composer: `guzzlehttp/guzzle` and `guzzlehttp/psr7` (default HTTP client and PSR-17
  factories), `halaxa/json-machine` (export streaming), `phrity/websocket` with `phrity/net-stream` (live WebSocket),
  and the PSR interfaces `psr/http-client`, `psr/http-factory`, `psr/http-message`, `psr/log`, `psr/simple-cache`.
- Optional, never required (Composer `suggest`): `ext-yajl_stream` and `ext-simdjson` make `ExportClient::streamProducts()`
  parse JSON faster (see [Exports](#exports)). Without them the pure PHP parser is used.
- Network access to `api.white.market` (GraphQL, live) and `export.white.market` (export files), over TLS.

## Quickstart

Authentication, search and a purchase, with a customId so a lost answer can be reconciled (see [Buying](#buying)):

```php
use WhiteMarket\Sdk\Buying\BuyOptions;
use WhiteMarket\Sdk\Errors\PurchaseOutcomeUnknownException;
use WhiteMarket\Sdk\Market\ProductFilter;
use WhiteMarket\Sdk\Models\{App, Money};
use WhiteMarket\Sdk\WhiteMarketClient;

$client = WhiteMarketClient::fromEnv(); // reads WHITEMARKET_PARTNER_TOKEN, exchanges it for access tokens itself
$name = 'AK-47 | Redline (Field-Tested)';
foreach ($client->market->search(new ProductFilter(app: App::Csgo, nameHash: $name)) as $product) {
    echo $product->id, ' ', $product->price, "\n";
}
try { // THIS SPENDS MONEY
    $buy = $client->buying->buyCheapest(App::Csgo, $name, maxPrice: Money::usd('25.00'), options: new BuyOptions(customId: 'order-1234'));
    echo $buy->deal->id, "\n";
} catch (PurchaseOutcomeUnknownException $e) {
    echo "Unknown outcome, check deal {$e->customId} before buying again\n";
}
```

Never hard-code a Partner Token in source. Read it from the environment or a secret store.

### Capabilities at a glance

| Module | Access | Main methods | Example |
|---|---|---|---|
| [Authentication](#authentication) | `$client->auth` | `ping`, `accessToken`, `refresh` | [`auth_and_ping.php`](examples/auth_and_ping.php) |
| [Market](#market) | `$client->market` | `search`, `iterate`, `get`, `stickerSuggestions`, `checkTradeUrl` | [`search_market.php`](examples/search_market.php), [`iterate_all_listings.php`](examples/iterate_all_listings.php) |
| [Buying](#buying) | `$client->buying` | `buyCheapest`, `buyProduct`, `buyByAssetId` | [`buy_cheapest_by_name.php`](examples/buy_cheapest_by_name.php), [`buy_product_by_id.php`](examples/buy_product_by_id.php) |
| [Deals](#deals-and-deal-tracking) | `$client->deals` | `get`, `statuses`, `waitFor`, `listAsBuyer`, `listAsSeller`, `history`, `iterateHistory`, seller actions, hold balances | [`track_deal_polling.php`](examples/track_deal_polling.php), [`track_deal_live.php`](examples/track_deal_live.php) |
| [Selling](#selling) | `$client->selling` | `list`, `reprice`, `remove`, `*Many`, `myProducts`, `setLifetimeForAll` | [`sell_semi.php`](examples/sell_semi.php), [`sell_manual.php`](examples/sell_manual.php) |
| [Inventory](#inventory) | `$client->inventory` | `list`, `iterate`, `refreshFromSteam` | none |
| [Wallet](#wallet) | `$client->wallet` | balances, deposits, withdrawals, transactions, transfers, credit | none |
| [Profile](#profile) | `$client->profile` | profile, trade URL, Steam proxy and token, e-mail, referrer | none |
| [Store](#store) | `$client->store` | `get`, `edit` | none |
| [Exports](#exports) | `$client->exports` | `meta`, `prices`, `streamPrices`, `streamProducts`, `download`, `affiliateLink` | [`export_prices_lookup.php`](examples/export_prices_lookup.php), [`export_products_stream.php`](examples/export_products_stream.php) |
| [Live](#live-updates) | `$client->live()` | product feed, personal deal events, WebSocket or SSE | [`ws_products_feed.php`](examples/ws_products_feed.php), [`sse_products_feed.php`](examples/sse_products_feed.php), [`ws_personal_deals.php`](examples/ws_personal_deals.php) |
| [Generated layer](#generated-layer) | `$client->generated` | one method per schema operation, `execute()` | [`raw_generated_operation.php`](examples/raw_generated_operation.php) |
| Buy orders | not available yet (P14) | | |

## Authentication

```php
$client->auth->ping();           // test_ping, returns the server's answer (string)
$token = $client->auth->accessToken(); // Secret; prints as "***"
$client->auth->refresh();        // forces a new access token, returns Secret
```

See [`examples/auth_and_ping.php`](examples/auth_and_ping.php).

The Partner Token is exchanged for a short-lived access token, kept in the token store (in memory by default, or
`Psr16TokenStore` to share it between workers) and renewed once fewer than 5 minutes of its lifetime remain. If the
server still answers 401, the SDK renews the token (or takes a newer one another worker stored) and replays the
request exactly once, mutations included: a 401 means the request was rejected before it ran. A second 401 throws
`AuthenticationException`. For a token you renew yourself: `create(accessToken: $jwt)`; the SDK never renews it, so a
401 reaches you. For custom logic:
`create(tokenProvider: ...)`. Tokens are `Secret` objects: they never appear in `__toString`, `var_dump`, serialization,
JSON or logs.

`create()` takes exactly one of `partnerToken` (preferred), `accessToken` (access token mode: a JWT you renew yourself)
or `tokenProvider`. See [Configuration reference](#configuration-reference) for the rest of the options.

### Token store (several processes)

By default each client exchanges the Partner Token for an access token on its own and keeps it in memory. Workers that
should share one access token use a PSR-16 cache:

```php
use WhiteMarket\Sdk\Auth\Psr16TokenStore;

$store = Psr16TokenStore::forPartnerToken($psr16Cache, getenv('WHITEMARKET_PARTNER_TOKEN') ?: '');
$client = WhiteMarketClient::create(partnerToken: getenv('WHITEMARKET_PARTNER_TOKEN') ?: null, tokenStore: $store);
```

The key is derived from a hash of the Partner Token, so accounts sharing a cache never share a token. A cache holds the
access token: protect it like a credential. `tokenStore` is only valid together with `partnerToken`.

Token store classes: `InMemoryTokenStore` (default), `Psr16TokenStore`, or your own `TokenStore` (`get()`, `set(Secret,
DateTimeImmutable)`, `clear()`). A custom `TokenProvider` (`accessToken(): Secret`, `refresh(): Secret`) goes to
`create(tokenProvider: ...)`.

## Market

```php
use WhiteMarket\Sdk\Models\PageRequest;

$page = $client->market->search(new ProductFilter(app: App::Csgo), new PageRequest(first: 50));
$next = $client->market->search(new ProductFilter(app: App::Csgo), new PageRequest(after: $page->endCursor));

foreach ($client->market->iterate(new ProductFilter(app: App::Csgo)) as $product) { // lazy: one request per page
    // ...
}

$product = $client->market->get('00000000-0000-4000-8000-000000000000'); // id or slug
```

`ProductFilter` has typed fields (`app`, `nameHash`, `nameHashes`, `name`, `ids`, `priceFrom`, `priceTo`, `floatFrom`,
`floatTo`, `phase`, `category`, `rarities`, `exteriors`, `statTrak`, `souvenir`, `stickerNames`, `deliveryType`,
`storeSlug`, `sort`, ...). A raw `MarketProductSearchInput` of the generated layer is accepted in place of it.

```php
$stickers = $client->market->stickerSuggestions('Natus', limit: 10, offset: 0); // StickerSuggestions
$check = $client->market->checkTradeUrl('https://steamcommunity.com/tradeoffer/new/?partner=0&token=placeholder'); // string
```

| Method | Returns |
|---|---|
| `search(?filter, ?PageRequest)` | `Page` (`items`, `endCursor`, `hasNextPage`, `totalCount`) |
| `iterate(?filter, ?int $pageSize)` | `Generator` of `Product`, one request per page |
| `get(string $idOrSlug)` | `Product` |
| `stickerSuggestions(string $search, int $limit = 10, int $offset = 0)` | `StickerSuggestions` |
| `checkTradeUrl(string $url)` | `string` |

See [`examples/search_market.php`](examples/search_market.php) and [`examples/iterate_all_listings.php`](examples/iterate_all_listings.php).

## Buying

```php
use WhiteMarket\Sdk\Buying\BuyOptions;
use WhiteMarket\Sdk\Errors\PurchaseOutcomeUnknownException;
use WhiteMarket\Sdk\Models\Money;

$customId = 'order-1234'; // 1 to 100 characters, yours
// 1. Persist $customId in your own storage BEFORE the call.
try {
    $result = $client->buying->buyCheapest(
        App::Csgo,
        'AK-47 | Redline (Field-Tested)',
        maxPrice: Money::usd('25.00'),
        options: new BuyOptions(customId: $customId),
    );
    echo $result->deal->id, $result->reconciled ? ' (found by reconciliation)' : '', "\n";
} catch (PurchaseOutcomeUnknownException $e) {
    // Neither success nor a rejection is proven. Do NOT buy again: check the deal by your customId.
    $state = $client->deals->get($e->customId);
}
```

```php
$byId = $client->buying->buyProduct('00000000-0000-4000-8000-000000000000', Money::usd('0.10'), new BuyOptions(customId: 'order-1235'));
$byAsset = $client->buying->buyByAssetId(App::Csgo, '1234567890123456789', options: new BuyOptions(customId: 'order-1236'));
```

| Method | Buys |
|---|---|
| `buyCheapest(App, string $nameHash, ?Money $maxPrice, ?Phase, ?DeliveryPromise, ?BuyOptions)` | the cheapest listing of an item name |
| `buyProduct(string $productId, ?Money $maxPrice, ?BuyOptions)` | one listing by id; `maxPrice` caps the price at the moment of purchase |
| `buyByAssetId(App, string $assetId, ?Money $maxPrice, ?BuyOptions)` | the listing of one Steam asset id, same cap |

`BuyOptions` has `customId` and `tradeUrl`; `BuyResult` has `deal`, `customId`, `reconciled`.
See [`examples/buy_cheapest_by_name.php`](examples/buy_cheapest_by_name.php) and [`examples/buy_product_by_id.php`](examples/buy_product_by_id.php).

How it works (SDK_SPEC 3.3):

- The `market_buy` mutation is sent **once and never retried**. Without `BuyOptions::$customId` the SDK generates one;
  `BuyResult::$customId` returns it.
- If the call fails ambiguously (timeout, 5xx, unreadable answer), the SDK waits (`BuyReconciliation::$delay`) and looks
  the deal up by `customId`, `attempts` times. If found, the result has `reconciled: true`.
- If no deal is found, `PurchaseOutcomeUnknownException` is thrown; it carries the `customId`. **Persist your own `customId`
  before calling**, so after a crash you can look the deal up with `deals->get($customId)` instead of buying twice.
- A rejection that proves the server did not run the purchase (insufficient funds, bad request, rejected credential, rate
  limit) propagates as its own exception.

## Deals and deal tracking

```php
use WhiteMarket\Sdk\Deals\Until;

$deal = $client->deals->get('order-1234');                      // deal id or customId
$states = $client->deals->statuses(['order-1234', '00000000-0000-4000-8000-000000000000']); // batch, one request
$state = $client->deals->waitFor('order-1234', Until::exchangedOrCompleted(), timeout: 600.0, pollInterval: 5.0);

foreach ($client->deals->iterateHistory() as $deal) { /* lazy pagination */ }
$open = $client->deals->listAsBuyer(); // also listAsSeller(), history()
```

- `Until::offered()`, `Until::exchangedOrCompleted()`, `Until::completed()`, `Until::final()` are predicates over a
  `DealState`; any `Closure(DealState): bool` works. A status this SDK does not know matches none of them.
- `waitFor` works for buyer and seller deals: it polls `statuses()` and, for a deal that call does not report (seller deals: `deal_buyer_statuses` covers purchases only), reads it with `get()` on every poll; `timeout: null` waits without limit, `0` polls once. On expiry it throws `DealWaitTimeoutException`
  with the last state.
- Seller-side calls: `requestCancel($id)`, `reportOfferSent($idOrCustomId, $offerId)`,
  `cancelSendingOffer($idOrCustomId, OfferCancelReason, $comment)`; balances held by deals: `holdBalance(Currency)`
  (`Money`), `holdBalances()`.
- Lists: `listAsBuyer(?statusesWith, ?statusesWithout)`, `listAsSeller(...)`, `history(?DealHistoryFilter, ?PageRequest)`
  (`Page`), `iterateHistory(?DealHistoryFilter, ?int $pageSize)`. `DealHistoryFilter` has `action`, `status`, `createdFrom`,
  `createdTo`, `priceFrom`, `priceTo`, `nameHash`, `sort`, ...
- `waitFor(..., live: $live)` wakes up early on live deal events (see [Live updates](#live-updates)).

Two ways to follow a deal:

- **Polling** (`waitFor` without `live:`, or your own loop over `statuses()`): simple, works anywhere, one batch query per
  interval (`statuses()` takes up to 300 ids in one request). See [`examples/track_deal_polling.php`](examples/track_deal_polling.php).
- **Live** (`waitFor(..., live: $live)` with a personal-channel session, or `subscribePersonal()` handlers): a deal event
  wakes the wait at once, and the poll stays as the safety net because live events are hints and the events of a
  reconnect gap are lost. See [`examples/track_deal_live.php`](examples/track_deal_live.php) and [`examples/ws_personal_deals.php`](examples/ws_personal_deals.php).

## Selling

```php
use WhiteMarket\Sdk\Errors\BatchIncompleteException;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Selling\BatchOutcome;
use WhiteMarket\Sdk\Selling\Listing;
use WhiteMarket\Sdk\Selling\ListingOptions;

$product = $client->selling->list('00000000-0000-4000-8000-000000000000', Money::usd('10.00'));
$client->selling->reprice($product->id, Money::usd('9.50'));
$client->selling->remove($product->id);

try {
    $results = $client->selling->listMany(
        [new Listing('00000000-0000-4000-8000-000000000000', Money::usd('10.00'))],
        new ListingOptions(),
    );
} catch (BatchIncompleteException $e) {
    foreach ($e->results as $r) {
        match ($r->outcome) {
            BatchOutcome::Succeeded, BatchOutcome::Failed => null, // the server answered
            BatchOutcome::Unknown => null,  // may have run: check with myProducts()
            BatchOutcome::NotSent => null,  // safe to send again
        };
    }
}
```

- `list`, `reprice`, `remove` and their `*Many` forms (`listMany`, `repriceMany(list<PriceChange>)`, `removeMany(list<string>)`)
  use the batch endpoints: one mutation per request, chunked by `sellingBatchSize`. Mutations are never retried.
- Each `BatchItemResult` has `index`, `outcome` (`Succeeded`, `Failed`, `Unknown`, `NotSent`), `product` and
  `errorCategory`.
- `BatchIncompleteException::$results` has one entry per item. Never resend the whole list: send only `NotSent` items and
  check `Unknown` ones with `myProducts()` / `iterateMyProducts()`.
- `ListingOptions` sets `deliveryType` (`Semi` by default), `deliveryPromise` and `lifetime`.
- Your own listings: `myProducts(?filter, ?PageRequest)` (`Page`) and `iterateMyProducts(?filter, ?int $pageSize)`.
  `setLifetimeForAll(int $seconds)` sets the lifetime of all your listings (`bool`).

```php
$mine = $client->selling->myProducts(new ProductFilter(app: App::Csgo), new PageRequest(first: 50));
foreach ($client->selling->iterateMyProducts() as $p) { /* lazy */ }
$client->selling->repriceMany([new PriceChange($product->id, Money::usd('9.00'))]);
$client->selling->removeMany([$product->id]);
$client->selling->setLifetimeForAll(86400);
```
- Item ids are an inventory UUID or a 64-bit Steam asset id as a **string**.

### SEMI and MANUAL flows

`ListingOptions::$deliveryType` picks who sends the Steam trade offer (`Auto` is reserved by the server and refused
client-side). Both flows start with `selling->list()`; after a buyer appears the deal shows up in
`deals->listAsSeller()` (WHITEMARKET_API.md 7):

- **SEMI** (default): WhiteMarket sends the offer. Wait for the deal status `NeedConfirmation`, confirm the offer in
  Steam Mobile, then wait for `Exchanged` (`Until::exchangedOrCompleted()`); after the trade protection ends the deal
  becomes `Completed`. Nothing else is called on the SDK. See [`examples/sell_semi.php`](examples/sell_semi.php).
- **MANUAL**: you send the offer. Wait for `SellerSendingSteamOffer`, send the item (`$deal->item->assetId`) in Steam
  yourself to the buyer's trade URL (`$deal->receiverTradeUrl?->reveal()`, a `Secret`: it carries the buyer's trade
  token, do not log it), then call `deals->reportOfferSent($dealId, $steamOfferId)`. If the item cannot be sent, call
  `deals->cancelSendingOffer($dealId, OfferCancelReason::..., $comment)` with the true reason (a wrong one can lead to
  sanctions; the comment is at most 255 characters of `a-z A-Z 0-9 space _ -`). See [`examples/sell_manual.php`](examples/sell_manual.php).

Both calls of the MANUAL flow are mutations: sent once, never retried. A missed `deliveryPromise` cancels the deal.

## Inventory, orders, wallet, profile, store

These modules have no script in `examples/`; the snippets are the reference.

### Inventory

```php
use WhiteMarket\Sdk\Inventory\InventoryFilter;

foreach ($client->inventory->iterate(new InventoryFilter(nameHash: 'AK-47 | Redline (Field-Tested)')) as $item) { /* lazy */ }
$page = $client->inventory->list(null, new PageRequest(first: 50)); // Page of InventoryItem
$client->inventory->refreshFromSteam(); // asks the server to re-read the Steam inventory, returns void
```

`list(?InventoryFilter, ?PageRequest)`, `iterate(?InventoryFilter, ?int $pageSize)`, `refreshFromSteam()`. A raw
`PersonInventorySearchInput` is accepted in place of the filter.

### Orders (buy orders)

Not available yet. The buy-order API (`orders`: create, edit, activate, deactivate, delete, search, `mine*`) is
specified in SDK_SPEC 3.7 but not implemented in this SDK: there is no `$client->orders` and `src/Orders/` is empty
(P14). The generated layer already has the raw operations (`orderMy()`, `orderMyActive()`,
`orderList()`, ...), see [Generated layer](#generated-layer).

### Wallet

```php
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Wallet\WalletTransactionFilter;

$balance = $client->wallet->balance(Currency::Usd); // Money
$client->wallet->balances();
$client->wallet->deposits();
$client->wallet->withdrawals();
$txs = $client->wallet->transactions(new WalletTransactionFilter(currency: Currency::Usd), new PageRequest(first: 50));
foreach ($client->wallet->iterateTransactions() as $tx) { /* lazy */ }
$client->wallet->transfers();                  // Page of WalletTransfer
foreach ($client->wallet->iterateTransfers() as $transfer) { /* lazy */ }
$credit = $client->wallet->credit();           // Credit
$client->wallet->takeCredit(Money::usd('100.00'));            // Debt, mutation
$client->wallet->transfer('<recipient>', Money::usd('1.00')); // WalletTransfer, mutation
```

Methods: `balance(Currency)`, `balances()`, `deposits()`, `withdrawals()`, `transactions(?filter, ?PageRequest)`,
`iterateTransactions(?filter, ?int $pageSize)`, `transfers(?PageRequest)`, `iterateTransfers(?int $pageSize)`, `credit()`,
`takeCredit(Money)`, `transfer(string $to, Money)`. `WalletTransactionFilter` has `createdFrom`, `createdTo`, `currency`,
`reason`, `status`, `buyer`, `seller`, `sort`, ...

Money mutations (`transfer($to, Money)`, `takeCredit(Money)`) are **never retried**. If one fails ambiguously, the SDK throws
`MutationOutcomeUnknownException` (`$operation`, previous = the original failure): the effect may or may not exist. Check
`wallet->transfers()` / `wallet->credit()` before trying again. A failure that proves the server did not run the mutation
(bad request, rejected credential, rate limit, a GraphQL error of the root field) propagates as its own exception.

### Profile

```php
$profile = $client->profile->get();
$client->profile->setTradeUrl('https://steamcommunity.com/tradeoffer/new/?partner=0&token=placeholder'); // placeholder
$proxy = $client->profile->steamProxy(); // ?Secret
$id = $client->profile->id();            // string
$client->profile->setSteamProxy(new \WhiteMarket\Sdk\Auth\Secret('<proxy>'), ttl: 3600);  // placeholder
$client->profile->setSteamToken(new \WhiteMarket\Sdk\Auth\Secret('<payload>'), ownProxyRequired: false); // placeholder
$client->profile->checkSteamToken();     // ?int
$client->profile->linkReferrer('<code>', tag: null);
```

| Method | Returns |
|---|---|
| `id()`, `get()` | `string`, `Profile` |
| `setTradeUrl(string)` | `bool` |
| `steamProxy()`, `setSteamProxy(Secret, ?int $ttl)`, `deleteSteamProxy()` | `?Secret`, `bool`, `bool` |
| `setSteamToken(Secret, bool $ownProxyRequired, ?int $ttl)`, `checkSteamToken()`, `deleteSteamToken()` | `bool`, `?int`, `bool` |
| `setEmail(string)`, `confirmEmail(string $code, ?string $sign)`, `resendEmailConfirmation()` | `Profile` |
| `linkReferrer(string $code, ?string $tag)`, `changeReferrerCode(string)` | `bool` |

Secrets are `Secret` objects (`steamProxy()`, `setSteamProxy(Secret)`, `setSteamToken(Secret)`): printing them gives `***`; call
`->reveal()` only where the value is really needed. Trade URLs, e-mail, referrer codes are sent with `#[\SensitiveParameter]`
and kept out of logs and exception messages. `Secret` lives in `WhiteMarket\Sdk\Auth\Secret`.

### Store

```php
use WhiteMarket\Sdk\Store\StoreSettings;

$store = $client->store->get();
$store = $client->store->edit(new StoreSettings(storeName: 'My store', isTradeHistoryPublic: false)); // returns Store
```

`get(): Store`, `edit(StoreSettings): Store`. `StoreSettings` fields (all optional): `avatar`, `banner`, `customAvatar`,
`storeName`, `isAchievementsPublic`, `isFavoritesPublic`, `isItemsOnSalePublic`, `isStoreNamePublic`,
`isTradeHistoryPublic`.

## Exports

`ExportClient` needs no authentication and works standalone; `$client->exports` is the same class.

```php
use WhiteMarket\Sdk\Export\ExportClient;
use WhiteMarket\Sdk\Export\ExportFormat;
use WhiteMarket\Sdk\Export\ExportKind;
use WhiteMarket\Sdk\Export\ExportWindow;
use WhiteMarket\Sdk\Export\NotModified;

$exports = ExportClient::create(cache: $psr16Cache); // the cache holds only ETag / Last-Modified, never a body

$meta = $exports->meta(ExportKind::Prices);              // data_as_of, generated_at, items
$prices = $exports->prices(maxAgeSeconds: 900);          // StaleExportException if data_as_of is older than 15 min
$prices = $exports->prices(conditional: true);           // PriceList|NotModified (needs a cache)
if ($prices instanceof NotModified) { return; }

$row = $prices->get('AK-47 | Redline (Field-Tested)', '282'); // by (market_hash_name, paint_index); ?PriceRow
$phases = $prices->allPhases('Doppler Case Hardened');
foreach ($exports->streamPrices(window: ExportWindow::Hour1) as $row) { /* PriceRow */ }
foreach ($exports->streamProducts(format: ExportFormat::Csv) as $row) { /* ProductRow, one lot; millions of rows */ }

$exports->download(ExportKind::Prices, 730, ExportWindow::All, ExportFormat::Json, sys_get_temp_dir() . '/prices.json'); // streamed to disk
$link = $exports->affiliateLink($row->marketProductLink, 'your-referral-code');
```

- Methods: `meta(ExportKind, app = 730, window, format)` (`ExportMeta`: `file`, `dataAsOf`, `generatedAt`, `items`),
  `prices(app, window, maxAgeSeconds, conditional)` (`PriceList|NotModified`), `streamPrices(app, window, maxAgeSeconds)`,
  `streamProducts(app, window, format, maxAgeSeconds)`, `download(kind, app, window, format, destination)`,
  `affiliateLink(marketProductLink, referralCode)`. `PriceList` is iterable and countable; `get($name, $paintIndex = '0')`
  returns `?PriceRow`, `allPhases($name)` all rows of the name. Kinds: `ExportKind::Prices`, `Products`; formats: `ExportFormat::Json`, `Csv`.
- Windows: `ExportWindow::All`, `Minutes10`, `Hour1`, `Hours12`, `Hours24`. `paint_index` is a string; `"0"` for items without a paint.
- `maxAgeSeconds` fetches the freshness sidecar first and fails before downloading stale data.
- `download` writes to a temporary file and renames it on success; on failure the destination is untouched.
- `streamPrices` reads the (small) prices file whole. `streamProducts` (JSON or CSV) parses the products file as it
  arrives, one row in memory and no size limit; a broken row or body fails at that row, after the rows before it.
  Breaking out of the loop closes the connection.
- JSON parser of `streamProducts`: `new ExportOptions(jsonStreamDecoder: JsonStreamDecoder::...)`. `Auto` (default)
  uses `ext-yajl_stream` if loaded (fastest), else `ext-simdjson`, else `halaxa/json-machine` (pure PHP). Install an
  extension with `pie install vlydev/yajl-stream` (needs libyajl) or `pecl install simdjson`, or in Docker with
  `install-php-extensions yajl_stream simdjson`. With `YajlStream`, an id above `PHP_INT_MAX` fails to decode instead
  of arriving as a digit string; pick `JsonMachine` or `Simdjson` if that matters. `JsonStreamDecoder::Simdjson->isAvailable()`
  tells whether the extension is loaded; naming an unavailable decoder throws when `ExportOptions` is built.
- Failures: `HttpException` (non-200), `TransportException` (network, refused redirect, oversize), `DecodeException`,
  `GeneralExportException` (destination not writable), `StaleExportException`.

See [`examples/export_prices_lookup.php`](examples/export_prices_lookup.php) and
[`examples/export_products_stream.php`](examples/export_products_stream.php).

## Live updates

`$client->live()` returns a `Live` session: the product feed (lots added, edited, removed) and your personal deal events,
over WebSocket (default) or SSE. Live events are hints: decide on GraphQL state after one.

```php
use WhiteMarket\Sdk\Live\Event\DealCompleted;
use WhiteMarket\Sdk\Live\Event\LiveConnected;
use WhiteMarket\Sdk\Live\Event\ProductAdded;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveTransport;

$live = $client->live();                                        // WebSocket; new LiveOptions(transport: LiveTransport::Sse) for SSE
$live->onConnected(fn (LiveConnected $c) => $c->reconnected && resync());
$live->on(ProductAdded::class, fn (ProductAdded $e) => print $e->marketHashName . "\n");
$live->subscribeProducts();
$live->subscribePersonal(fn ($e) => $e instanceof DealCompleted && print $e->dealId . "\n");
$live->run(60.0);                                               // handlers for 60 s; run() alone goes on until close()
```

- Methods of `Live`: `connect()`, `subscribeProducts(?Closure)`, `subscribePersonal(?Closure)`, `on(string $type, Closure)`,
  `onConnecting()`, `onConnected()`, `onDisconnected()`, `onError()`, `poll(float $timeout)`, `events()`, `run(?float $duration)`,
  `close()`.
- Event types (`WhiteMarket\Sdk\Live\Event`):

| Event | Channel | Fields |
|---|---|---|
| `ProductAdded`, `ProductRemoved` | products | `id`, `appId`, `assetId`, `classId`, `instanceId`, `price`, `marketHashName`, ... (`ProductAdded` also `float`, `paintIndex`, `stickers`, ...) |
| `ProductEdited` | products | `id` and the changed fields (others null) |
| `DealInitiated`, `DealOffered`, `DealNeedConfirmation`, `DealExchanged`, `DealHold`, `DealCompleted`, `DealCancelled`, `DealExpired` | personal | `dealId`, `action`, `productName`, `steamId` (base `DealEvent`) |
| `LiveConnected`, `LiveDisconnected` | session | `reconnected`; `code`, `reason`, `willReconnect` |
| `UnknownEvent` | any | `type`, `rawContent` |

  Examples: [`examples/ws_products_feed.php`](examples/ws_products_feed.php), [`examples/sse_products_feed.php`](examples/sse_products_feed.php),
  [`examples/ws_personal_deals.php`](examples/ws_personal_deals.php), [`examples/track_deal_live.php`](examples/track_deal_live.php).
- Consume events by handlers (`on()`, `subscribeProducts($handler)`, `subscribePersonal($handler)`, `onConnected()`,
  `onDisconnected()`, `onError()`) with `run()`, or pull them: `poll($timeout)` returns the next event or null,
  `events()` is a generator that blocks until `close()`. Handlers also run inside `poll()` and `events()`.
  Everything is synchronous; nothing happens between calls.
- The connection is made on first use (or `connect()`) and kept up: after a drop the session reconnects with backoff, a
  fresh token and the same subscriptions. `LiveOptions::$maxReconnectAttempts` bounds the attempts (null: unlimited).
- Live is TLS only (`wss` / `https`): the base URL is https-only, so a plain `ws` / `http` endpoint cannot be configured.
- Close codes: 3000-3499 and 4000-4499 reconnect; 3500-3999 and 4500-4999 are terminal. Any other code (WebSocket 1000,
  1001, 1006, ...) reconnects with the backoff too, but at most 5 times in a row (`LiveSession::MAX_OTHER_CLOSES`);
  the 6th ends the session with `LiveConnectionException`. The count resets after a connection stayed up 60 s.
- **After a reconnect `LiveConnected::$reconnected` is true and the events of the gap are lost** (the server keeps no
  history): re-read what you care about through GraphQL. `LiveDisconnected` tells the close `code`, a server-controlled
  `reason` (server text as sent, not redacted: printable ASCII cut to 128 bytes) and `willReconnect`.
- An exception thrown by a handler propagates out of `poll()`, `events()` or `run()`; the connection stays open, so you
  may call again or `close()`. It does not go to `onError()`, which gets non-fatal problems (a malformed message, a
  failed attempt before the next retry).
- A terminal failure (a close code that forbids reconnecting or a sixth other-code close in a row, a second token rejection, attempts used up) is thrown
  from the blocking call as `LiveConnectionException` (`closeCode`, `reason`) or the matching `HttpException`, and ends
  the session. Branch on `closeCode`, never on `reason`.
- Unknown message types arrive as `UnknownEvent`; `LiveEvent::class` matches every event.
- `$client->deals->waitFor($id, $until, live: $live)` waits in `$live->poll()` instead of sleeping and polls
  `statuses()` early when a deal event for that deal arrives. Other events (product events included) neither poll nor
  shorten the interval. The caller owns the session: connect, subscribe, close.
- SSE uses the client you injected into `create()` as is: its timeouts decide, not `LiveOptions`. SSE subscribes at
  connect time, so a later `subscribeProducts()` reconnects. A chunked SSE response may add up to one read slice
  (`readSlice`, 1 s) of latency; the WebSocket has no such delay. A blocking call may overrun its deadline by up to
  one slice (SSE) or while a partial WebSocket frame is pending.

## Generated layer

`WhiteMarket\Sdk\Generated` holds one typed method per operation of the partner schema, in `GeneratedClient`
(`marketList()`, `marketBuy()`, `dealItem()`, `orderMy()`, ...), with typed inputs (`Generated\Input`), enums with an
unknown fallback (`Generated\Enum`), fragments and one result class per operation. It is committed to the repository and
regenerated by `make codegen`; never edit it by hand. The high-level API is built on top of it and maps it to the
models of this README.

`GeneratedClient::execute(string $document, array $variables = [], ?string $operationName = null): array` runs a
hand-written document and returns its raw `data`. A document that is not clearly a query is treated as a mutation and
is therefore never retried.

```php
$data = $client->generated->execute('query Ping { test_ping }'); // raw `data` array
```

`$client->generated` is that `GeneratedClient`, on the client's own token, retry policy and error mapping
([`examples/raw_generated_operation.php`](examples/raw_generated_operation.php)). Mutations are never retried there either, and a purchase made there has no
`customId` reconciliation: prefer the high-level methods whenever one exists.

## Errors

All SDK exceptions extend `WhiteMarketException` (a `RuntimeException`). Branch on the class or on the error category,
**never on the message**.

```
WhiteMarketException
|- HttpException (non-2xx): BadRequestException (400), AuthenticationException (401), RateLimitException (429),
|    ServerException (5xx: ServiceUnavailableException, GeneralServerException), GeneralHttpException
|- GraphQLException (errors in a 200 answer): NotFoundException, GeneralGraphQLException  [$errors: GraphQLErrorEntry]
|- TransportException, DecodeException, LiveConnectionException (live session ended; `closeCode`, `reason`)
|- ExportException: StaleExportException, GeneralExportException
|- PurchaseOutcomeUnknownException, MutationOutcomeUnknownException, BatchIncompleteException, DealWaitTimeoutException
```

```php
use WhiteMarket\Sdk\Errors\ErrorCategory;
use WhiteMarket\Sdk\Errors\GraphQLException;

try {
    $client->market->get('00000000-0000-4000-8000-000000000000');
} catch (GraphQLException $e) {
    foreach ($e->errors as $error) {
        if (ErrorCategory::isNotFound($error->category)) { /* ... */ }
    }
}
```

Exception messages never contain tokens or request bodies.

## Retries and timeouts

Queries are retried on a network error before a response and on HTTP 429, 500, 502, 503 and 504; export downloads on a
network error, 429 and any 5xx. `RetryPolicy` sets the attempts (`maxAttempts`, 3 in total by default) and the
backoff: exponential with full jitter, 0.5 s base, factor 2, capped at 30 s. A 429 or 503 with `Retry-After` is not
retried: the exception carries `retryAfter` and the SDK never sleeps for a time the server chose.
**Mutations are never retried automatically**, by the transport or by helpers. The single replay after a 401 (see
Authentication) is separate from these retries. `TransportOptions` sets connect and
request timeouts; `ExportOptions` sets connect and idle timeouts and the body limit.

## Unknown values

New server enum values never throw. A field typed `DealStatus|UnknownEnumValue` holds the enum case when the SDK knows the
value and an `UnknownEnumValue` (`->enum`, `->value`) otherwise:

```php
if ($state->status instanceof DealStatus) { /* known */ } else { echo $state->status->value; }
```

## Money and numbers

Money and wear (`float`) values are exact decimals: `Money` holds a `Decimal` (a string-backed value) and a currency,
`Money::usd('10.50')`. Never binary floats. Compare with `Money::equals()` and `Decimal::compare()`. 64-bit ids (asset,
class, instance) are `string`s.

## Configuration reference

```php
$client = WhiteMarketClient::create(partnerToken: getenv('WHITEMARKET_PARTNER_TOKEN') ?: null);
$client = WhiteMarketClient::fromEnv(); // WHITEMARKET_PARTNER_TOKEN, optional WHITEMARKET_BASE_URL
```

`create()` takes exactly one of `partnerToken`, `accessToken` or `tokenProvider`. `fromEnv()` reads only
`WHITEMARKET_PARTNER_TOKEN` and `WHITEMARKET_BASE_URL` (with `getenv()`: a dotenv loader must export the variables to the
process); it takes the same options except the credentials. The Partner Token and the access token are sent to whatever
host `baseUrl` / `WHITEMARKET_BASE_URL` points at, so it must only ever point at the WhiteMarket API (or a trusted proxy).
Invalid option values throw `InvalidArgumentException` when the option object is built.

| Parameter | Meaning and defaults |
|---|---|
| `partnerToken` / `accessToken` / `tokenProvider` | credentials, exactly one; the first is exchanged and renewed by the SDK |
| `tokenStore: TokenStore` | where the access token of `partnerToken` is kept; `InMemoryTokenStore` by default; only valid with `partnerToken` |
| `transport: TransportOptions` | see below |
| `retryPolicy: RetryPolicy` | retries of queries; `maxAttempts` 3 (total attempts), `baseDelaySeconds` 0.5, `factor` 2.0, `maxDelaySeconds` 30.0; `RetryPolicy::none()` disables retries |
| `logger: Psr\Log\LoggerInterface` | PSR-3; `NullLogger` (silent) by default |
| `httpClient`, `requestFactory`, `streamFactory` | PSR-18 / PSR-17 objects used instead of Guzzle (see the trust boundary below) |
| `buyReconciliation: BuyReconciliation` | `delay` 15.0 s (10 to 20), `attempts` 3, `interval` 5.0 s of the lookup after an ambiguous purchase |
| `sellingBatchSize` | items per request of the `*Many` selling methods, default 100, at least 1 |
| `exportOptions: ExportOptions` | see below |
| `exportCache: Psr\SimpleCache\CacheInterface` | holds only ETag and Last-Modified of export files; none by default (then `conditional: true` has nothing to compare) |

`TransportOptions` (GraphQL): `baseUrl` `https://api.white.market` (https origin only), `graphqlPath` `/graphql/partner`,
`connectTimeout` 10.0 s, `requestTimeout` 30.0 s, `maxResponseBytes` 32 MiB, `userAgentSuffix` null.

`ExportOptions`: `baseUrl` `https://export.white.market`, `connectTimeout` 10.0 s, `idleTimeout` 60.0 s (no total
timeout), `maxBodyBytes` 64 MiB (decoded), `userAgentSuffix` null, `jsonStreamDecoder` `JsonStreamDecoder::Auto`.

`LiveOptions` (passed to `$client->live()`): `transport` `LiveTransport::WebSocket` (or `Sse`), `wsPath` `/ws_endpoint`,
`ssePath` `/sse_endpoint`, `connectTimeout` 10.0 s, `readSlice` 1 s, `maxMessageBytes` 1 MiB (1024 to 64 MiB),
`maxReconnectAttempts` null (unlimited), `userAgentSuffix` null.

```php
use WhiteMarket\Sdk\Transport\RetryPolicy;
use WhiteMarket\Sdk\Transport\TransportOptions;

$client = WhiteMarketClient::fromEnv(
    transport: new TransportOptions(requestTimeout: 15.0),
    retryPolicy: new RetryPolicy(maxAttempts: 5),
    logger: $psr3Logger,
);
```

### Injected HTTP client and its trust boundary

Without `httpClient` the SDK uses Guzzle with the timeouts of `TransportOptions` and a separate client for mutations that
never sends a request twice. An injected PSR-18 client is used as is, for queries, mutations and exports:

- the SDK cannot set its timeouts (PSR-18 has no API for them) and cannot enforce its limits on the connection;
- it must not resend a request whose connection failed, or a mutation may run twice;
- **redirects (REDIRECT-1, see [`docs/DEVIATIONS.md`](docs/DEVIATIONS.md))**: the default client follows no redirects on its own;
  the export downloader follows at most 3 same-host https redirects itself. An injected client that follows redirects
  internally (for example Symfony `Psr18Client`, default 20 redirects) hides the hops from the SDK and **may forward
  credentials (the `Authorization` header) to another host**. Turn redirect following off in the injected client.

## Logging and redaction

The SDK logs through the PSR-3 logger you pass (`logger:`), and nothing by default. A logging failure never changes the
outcome of a call: the logger is wrapped so a throwing handler cannot escape a purchase that already ran.

- `debug`: one line per HTTP attempt (`operation`, `attempt`, `status`, `durationMs`); `info`: each retry (`attempt`,
  `delaySeconds`) and live connect, disconnect and close events; `notice`: an injected HTTP client is used as is;
  `warning`: an export cache read or write failed (class name only).
- Log records and exception messages never hold tokens, request or response bodies, or variables. `Secret` prints as
  `***` everywhere (`__toString`, `var_dump`, serialization, JSON), token parameters carry `#[\SensitiveParameter]` so
  they are hidden in stack traces, and the start of an HTTP error body that an `HttpException` keeps is redacted
  (credentials become `***`, invalid UTF-8 is replaced, the length is capped).
- Redaction works on markers (words like `Authorization`, `token`), not on understanding: do not log your own secrets
  next to the SDK's records.

## Versioning and schema updates

The package follows [Semantic Versioning](https://semver.org/). While the version is `0.x`, a minor release may break the
public API; every break is listed under "Breaking" in [`CHANGELOG.md`](CHANGELOG.md) as `before -> after`. Public classes,
methods and constants are the contract; classes marked `@internal` (transport, generated internals, mappers) are not.

The generated layer follows the partner GraphQL schema (`schema/partner.graphql`, never edited by hand). The
`schema-sync.yml` workflow runs daily (05:17 UTC), on demand and on a `partner-schema-changed` dispatch:

1. It introspects the public schema and rewrites `schema/`. No difference: it stops.
2. It summarizes the change (breaking, dangerous, safe) into `schema/CHANGES.md`, regenerates the operations and the PHP
   layer and runs `make check`.
3. It opens or updates the pull request `chore(schema): sync partner schema` from the branch `schema-sync/auto` with
   the label `schema-update` (plus `breaking` when breaking changes were found). When `make check` fails the pull request
   is a draft with the failure summary. It never pushes to `main`.
4. A human reviews: does the high-level layer need changes, and which SemVer bump follows.

New enum values, event types and fields from the server never crash the SDK: they arrive as unknown values.

### Live smoke test

`make live-smoke` makes read-only calls against the real API (it needs `WHITEMARKET_PARTNER_TOKEN` in the environment)
and writes `var/live-smoke/report.md`; it is not part of `make check`. The weekly workflow `live-smoke.yml` runs the
same with the repository secret `WM_PARTNER_TOKEN`.

## Security

- Tokens: `Secret` never reveals its value in `__toString`, `var_dump`, serialization or JSON; token parameters carry
  `#[\SensitiveParameter]`; logs and exception messages are redacted.
- The Partner Token is a long-lived credential: keep it in the environment or a secret store, never in the repository.
- TLS certificate verification is always on in the default HTTP clients, and no SDK option disables it. An injected
  PSR-18 client is used as is: its own TLS, CA and proxy settings apply. Mind the trust boundary of an injected HTTP client (above), especially
  redirects. A client injected for live SSE must not follow redirects and must not log request URLs: the SSE URL carries
  the access token.
- Report vulnerabilities as described in [`SECURITY.md`](SECURITY.md); do not open a public issue for them.

## Examples

Runnable scripts in [`examples/`](examples/). Each reads the token from `WHITEMARKET_PARTNER_TOKEN` (or from a
git-ignored dotenv file, see `.env.example`) unless it says otherwise, and is read-only unless its name says `buy` or
`sell`. The `buy_*` and `sell_*` scripts spend money or list items: they print what they would do and act only with
`--yes`. A failure is printed with its class, HTTP status and, for GraphQL errors, each error's category, path and server
message (`examples/Support/failure.php`); code branches on the category, never on that text.

| Script | What it shows |
|---|---|
| `auth_and_ping.php` | exchange the Partner Token, call `test_ping` |
| `search_market.php` | first page of cheap CS2 listings |
| `iterate_all_listings.php [max]` | lazy pagination, stops after `max` listings (default 200) |
| `buy_cheapest_by_name.php "<name>" <max USD> [--custom-id=..] [--yes]` | purchase with `customId` and `PurchaseOutcomeUnknownException` handling |
| `buy_product_by_id.php <product id> <max USD> [--custom-id=..] [--yes]` | one listing by id: read and price check first, then `buyProduct()` with the same cap and `customId` |
| `track_deal_polling.php <deal id or customId> [timeout s]` | `deals->waitFor()` by polling |
| `track_deal_live.php <deal id or customId> [timeout s]` | `deals->waitFor(..., live: $live)` woken by personal deal events |
| `sell_semi.php <item> <price USD> [--wait=s] [--yes]` | SEMI selling flow |
| `sell_manual.php list\|show\|report ...` | MANUAL selling flow: what to send and to whom, reports the Steam offer |
| `export_prices_lookup.php` | prices export, lookup by `(market_hash_name, paint_index)`; no token |
| `export_products_stream.php` | products export streamed row by row, stops after 10,000 rows; no token |
| `sse_products_feed.php` | live product feed over SSE for 60 seconds |
| `ws_products_feed.php` | live product feed over WebSocket for 60 seconds, with the `reconnected` resync hint |
| `ws_personal_deals.php [seconds]` | your personal deal events over WebSocket |
| `raw_generated_operation.php` | a typed generated operation and a raw document through `GeneratedClient` |

## Contributing

Setup, the `make` targets and the review rules are in [`CONTRIBUTING.md`](CONTRIBUTING.md). Run `make check` before a pull
request; the generated layer changes only through `make codegen`.

## License

MIT, see [`LICENSE`](LICENSE).

## Documentation

- Partner API docs: https://api.white.market/docs_partner/index.html
- Partner GraphQL endpoint: `https://api.white.market/graphql/partner`; export files: `https://export.white.market/v1/`
- SDK specification shared by all WhiteMarket SDKs: [`docs/SDK_SPEC.md`](docs/SDK_SPEC.md)
- API facts used by the SDK: [`docs/WHITEMARKET_API.md`](docs/WHITEMARKET_API.md)
- Deviations from the spec: [`docs/DEVIATIONS.md`](docs/DEVIATIONS.md)
- Design decisions: [`docs/decisions/`](docs/decisions/)
