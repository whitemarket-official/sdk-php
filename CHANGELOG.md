# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).
While the version is `0.x`, a minor release (`0.2.0`) may contain breaking changes; they are always listed under
"Breaking".

## [Unreleased]

### Added

- `DealCancelReason::InstantExchangeFailed` and seven `TransactionReason::InstantExchange*` cases (transfer, refund
  and hold release, each in and out). These values entered the public partner schema on 2026-11-10; before this
  release they arrived as `UnknownEnumValue`.
- Generated layer: `MarketProductState::AVAILABLE_FOR_TRADE` and the optional input field
  `MarketProductSearchInput::$forTrade`. `ProductFilter` does not expose `forTrade` yet.

### Changed

- `DealStatus::Queued` can now be passed in the status filters of `deals()->listAsBuyer()` and
  `deals()->listAsSeller()`. Before, the SDK threw `InvalidArgumentException` because the schema did not accept it.

## [0.1.0] - 2026-11-02

First public release of the PHP SDK for the WhiteMarket partner API.

### Requirements

- PHP 8.2 or newer. PHP 8.1 is not supported because of an engine bug that corrupts memory (ADR 0002).
- Extensions `curl` and `zlib`.
- Optional: `ext-yajl_stream` or `ext-simdjson` for faster parsing of the products export. Without them the SDK uses
  `halaxa/json-machine`.
- Any PSR-18 HTTP client works. Without one, the SDK uses Guzzle.

### What you can do

- **Connect.** Use `WhiteMarketClient::create(partnerToken: ...)`, or `WhiteMarketClient::fromEnv()`, which reads
  `WHITEMARKET_PARTNER_TOKEN`. The SDK exchanges the Partner Token for an access token, caches it in memory or in any
  PSR-16 cache, and refreshes it before it expires. `$client->auth->ping()` checks the connection.
- **Market** (`$client->market`):
  - search listings with filters and sorting;
  - walk through all pages lazily;
  - get one listing, sticker suggestions, or check a trade URL.
- **Buying** (`$client->buying`):
  - `buyProduct()`, `buyCheapest()` and `buyByAssetId()`, each with an optional maximum price;
  - every purchase has a `customId`, which the SDK generates if you do not pass one.
- **Deals** (`$client->deals`):
  - get a deal, or the statuses of many deals in one request;
  - your deals as buyer or seller, and the deal history;
  - cancel a deal or report a sent offer;
  - `waitFor()` a status by polling, or faster with live events.
- **Selling** (`$client->selling`):
  - list items, change prices and remove listings, one by one or in batches;
  - see your own listings.
- **Inventory** (`$client->inventory`): list your items and refresh them from Steam.
- **Wallet** (`$client->wallet`):
  - see balances, deposits, withdrawals, transactions, transfers and credit;
  - take credit or make a transfer.
- **Profile and store** (`$client->profile`, `$client->store`): trade URL, Steam proxy and token, email, referrer,
  store settings.
- **Exports** (`$client->exports`, or `ExportClient::create()` without a token):
  - the prices file and export meta;
  - the products file as JSON or CSV, read row by row without loading the whole file into memory;
  - download an export to a file, or build affiliate links;
  - conditional requests with `ETag` / `Last-Modified`.
- **Live updates** (`$client->live()`):
  - new, changed and removed listings, and events of your own deals;
  - over WebSocket (default) or SSE;
  - the connection reconnects and subscribes again on its own.
- **Generated layer** (`$client->generated`): a typed method for every partner API operation, plus `execute()` for
  your own GraphQL document. Use it for anything the high-level API does not cover yet.
- **Examples:** 15 runnable scripts in `examples/`. The ones that buy or sell only print what they would do unless you
  pass `--yes`.

### Safe by default

- **Mutations are never sent twice:** purchases, transfers, listings and every other mutation.
  - Each mutation uses a fresh connection, so neither Guzzle nor curl can resend it either.
  - If the answer to a purchase is lost (network error, 5xx), the SDK looks the deal up by `customId`.
  - If the purchase still cannot be confirmed, the SDK throws `PurchaseOutcomeUnknownException` with the `customId`,
    so you can check the deal later.
  - For a transfer or `takeCredit()` in the same situation, you get `MutationOutcomeUnknownException`.
- **Queries are retried** with backoff on network errors and on HTTP 429, 500, 502, 503 and 504.
  - A 429 or 503 with `Retry-After` is not retried. It throws at once, and the exception carries `retryAfter`, so you
    decide when to retry.
- **Money and wear are exact decimals** (`Decimal`, `Money`), never `float`. Steam asset, class and instance ids are
  strings.
- **Secrets stay hidden.** Tokens, the Steam proxy and trade URLs are `Secret` objects. They do not show up in
  `var_dump`, `print_r`, `json_encode`, `serialize`, logs or exception traces.
- **Logs are minimal.** They contain only the operation name, attempt number, HTTP status and duration.
- **The connection is protected:**
  - TLS verification is always on;
  - API requests do not follow redirects;
  - export downloads follow at most 3 redirects, and only to the same https host.
- **Responses are size-limited:**
  - 32 MiB for an API response;
  - 64 MiB for the prices file and export meta;
  - 128 KiB per row when you stream the products file.

  All limits are counted after decompression. `download()` has no size limit.
- **New server values do not break your code.** An enum value or live event the SDK does not know yet arrives as
  `UnknownEnumValue` or `UnknownEvent`.
- **Releases come with a build provenance attestation** (GitHub `actions/attest`).

### Known limitations

- **No high-level `orders` module yet.** Use the generated `order*` operations through `$client->generated`. The
  module is planned for 0.2.0.
- **Errors on purchases and money operations.** If `market_buy`, `transfer` or `takeCredit` returns a GraphQL error,
  the SDK treats the action as not done. The server does not publish its error categories, so an error that arrives
  after the action already went through is not recognized.
- **No retry key for selling.** Selling mutations have no idempotency key. If an answer is lost, check
  `myProducts()` before you try again.
- **Live updates:**
  - product prices are assumed to be in USD;
  - events missed while reconnecting are not replayed. When `LiveConnected::$reconnected` is true, reload what you
    need.
- **Batch size.** The server's maximum batch size for selling is not confirmed. The SDK sends 100 items per request;
  change it with `sellingBatchSize`.
- **Products CSV.** The SDK assumes the CSV columns have the same names as the JSON keys.
- **Missing deal fields.** The SDK does not request a deal product's `params.updatedAt` and `params.order`, because the
  server currently fails when asked for them.

[Unreleased]: https://github.com/whitemarket-official/sdk-php/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/whitemarket-official/sdk-php/releases/tag/v0.1.0
