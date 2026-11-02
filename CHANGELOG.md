# Changelog

All notable changes to this project are documented in this file.
The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Known limitations

- No `orders` module: the public schema hides every order type (proposal P14, DEVIATIONS).
- The `extensions.category` catalogue is unknown: a post-commit error on the root path of `market_buy`, `transfer` or
  `takeCredit` is read as a rejection (SDK_SPEC 3.3 rule 6, 3.5 rule 8, 3.8 rule 5).
- Live product prices are assumed to be USD (owner decision O1); live has no gap-free resume (no `recover`), resync on
  `LiveConnected::$reconnected`.
- `MarketProductResult.index` base and the maximum batch size of `market_*_multiple` are unconfirmed (default 100).
- The products CSV header is assumed to name the JSON keys (SDK_SPEC 3.10 rule 11).
- Of the 13 operations that no longer select `params.updatedAt` / `params.order`, `deal_history`,
  `deal_history_item`, `deal_item` and `market_buy` were checked live (a purchase went through after the fix); the
  others are fixed on the same evidence.

### Added

- `Deal::$receiverTradeUrl` (a `Secret`, null for an archived deal): the buyer's trade URL a MANUAL seller sends the
  item to (SDK_SPEC 3.4 rule 11). `examples/sell_manual.php` prints the item, its asset id and that URL when a buyer
  appears, and has a `show <deal id>` mode for a deal that already waits for the offer.

### Changed

- Schema sync 2026-11-02 (generated layer only): the schema now exposes `CSGOMarketOrder` / `CSGOMarketOrderPublic`.
  The generated `order*` operations select their fields, and `orderActivate/Deactivate/Delete/Edit/New` and
  `orderList/My*` return the new order classes instead of a bare `UnknownTypeValue` (the union keeps it for unknown
  types). `market_list`, `market_item`, `market_my`, `market_new` and `market_edit` keep `item.order { __typename }`
  through overrides (DEVIATIONS); the high-level API is unchanged.
- Live (SDK_SPEC 3.11, owner decision 2026-11-01): a close/disconnect code outside 3000-3499, 3500-3999, 4000-4499 and
  4500-4999 (WebSocket 1000, 1001, 1006, ...) now reconnects with the backoff instead of ending the session, at most 5
  consecutive times; the 6th throws `LiveConnectionException` (code + reason). The count resets after a connection
  stayed up 60 s. 3500-3999 and 4500-4999 stay terminal. Live is TLS only (the https-only base URL); close reasons are
  server text as before; handler exceptions propagate out of `poll()` / `run()` / `events()` / `waitFor()`.

### Fixed

- `deals->waitFor()` never saw a deal where the caller is the seller: `deal_buyer_statuses` returns nothing for it, so the
  wait hung until the timeout. When the status query does not report the deal, `waitFor` now reads it with `get()`
  (`deal_item`, then the archive), one request per poll from then on.
- Generated layer: an interface field selected at the depth limit as `{ id }` (no `__typename`, e.g.
  `market_list` ... `product.item`) failed to decode, so `market->search` / `market->iterate` threw `DecodeException`
  on live answers. The operation generator now selects `__typename id` there (SDK_SPEC 1.2, proposal P22 B), the
  hand-written overrides do the same, and such a field decodes by its type like any other interface selection; an
  interface selected without `__typename` still decodes as one class with the interface's own fields.
- Buying and every deal-returning operation: the server fails `params.updatedAt` of a deal's product item
  ("Internal error occurred") and `params.order` (HTTP 500), and rolls a mutation back with it: `buying->buy*` reported
  `PurchaseOutcomeUnknownException` and bought nothing; `deals->history()` and the archived `deals->get()` failed too,
  and the seller/buyer lists and the listing/cancel/report mutations select the same fields. 13 operations no longer select these two fields (overrides,
  DEVIATIONS), pinned by `ProductHistoryParamsSelectionTest`.

### Added

- `buying->buyProduct()` and `buyByAssetId()` take an optional `?Money $maxPrice` (before `$options`) and send it as
  `market_buy` `maxPriceInput`, like `buyCheapest()` (proposal P24). The server refuses a purchase above it
  (observed with a product id: category `market.product.not_found_in_specified_price`, no deal). An unknown currency throws `InvalidArgumentException` before any request.
- `WhiteMarketClient::$generated` (SDK_SPEC 1.1): the `GeneratedClient` (typed operations and `execute()`) on the
  client's own token, retry policy and error mapping.
- Documentation for 0.1.0 (SDK_SPEC 9, T7.1): README completed in the order of the spec (badges, requirements, quickstart
  with auth + search + buy, deal tracking by polling and live, SEMI and MANUAL selling flows, orders status, generated
  layer, configuration reference with defaults, logging and redaction, versioning and the schema-sync pull request, live
  smoke test, examples list, contributing, security, license, partner docs links).
- Examples, the 14 of SDK_SPEC 9: `auth_and_ping`, `search_market`, `iterate_all_listings` (bounded, lazy),
  `buy_cheapest_by_name` (customId and `PurchaseOutcomeUnknownException` handling), `buy_product_by_id` (beyond
  SDK_SPEC 9: price check, then `buyProduct()`), every example prints a failure with its class, HTTP status, GraphQL
  categories, paths and server messages (`examples/Support/failure.php`), `track_deal_polling`,
  `track_deal_live`, `sell_semi`, `sell_manual`, `export_prices_lookup`, `export_products_stream`, `sse_products_feed`,
  `ws_products_feed`, `ws_personal_deals`, `raw_generated_operation`. Renamed: `auth_ping` -> `auth_and_ping`,
  `live_products` -> `ws_products_feed` (plus the new `sse_products_feed`), `live_personal` -> `track_deal_live`. The
  `buy_*` and `sell_*` examples only print what they would do unless `--yes` is given.
- `make live-smoke` and the weekly `live-smoke.yml` workflow: read-only calls against the real API, report in
  `var/live-smoke/report.md`.
- Dependabot for Composer and GitHub Actions.
- Live module (SDK_SPEC 3.11, T6.2/T6.3): `WhiteMarketClient::live(LiveOptions $options = new LiveOptions()): Live` over
  WebSocket (default) or SSE. `Live\Live`: `subscribeProducts()`, `subscribePersonal()`, `on()`, `onConnecting()`,
  `onConnected()`, `onDisconnected()`, `onError()`, `connect()`, `poll()`, `events()`, `run()`, `close()`. Events:
  `ProductAdded`, `ProductEdited`, `ProductRemoved`, eight `DealEvent` subclasses (`DealInitiated`, `DealOffered`,
  `DealNeedConfirmation`, `DealHold`, `DealExchanged`, `DealCompleted`, `DealCancelled`, `DealExpired`),
  `UnknownEvent`, and the lifecycle values `LiveConnected` (`reconnected`) and `LiveDisconnected` (`code`, `reason`,
  `willReconnect`). `Live\LiveOptions` (`transport`, `wsPath`, `ssePath`, `connectTimeout`, `readSlice`,
  `maxMessageBytes`, `maxReconnectAttempts`, `userAgentSuffix`), `Live\LiveTransport`, `Errors\LiveConnectionException`
  (`closeCode`, `reason`). The session reconnects with backoff, a fresh token and resubscription; a handler exception
  propagates out of `poll()` / `events()` / `run()`. An injected PSR-18 client is used for SSE as is. Examples
  `examples/ws_products_feed.php`, `examples/track_deal_live.php` (first named `live_products.php`, `live_personal.php`); memory test of 100k messages.
- `Deals\DealsApi::waitFor()` gains the last parameter `?Live $live = null`: it waits in `$live->poll()` and polls early
  on a deal event for the waited deal; timeout semantics unchanged.
- Exports part 1 (SDK_SPEC 3.10, T5.1), `$client->exports` and the standalone `Export\ExportClient::create()` (no
  authentication): `meta()`, `prices()` (-> `PriceList`, or `NotModified` for `conditional: true`; `maxAgeSeconds`
  checks the meta first and throws `StaleExportException`), `streamPrices()`, `download()` (decoded stream to a path via
  a private temporary file next to it + atomic rename, or to a PSR-7 stream; not size-bounded) and `affiliateLink()`. New: `ExportOptions`
  (`baseUrl`, `connectTimeout`, `idleTimeout` 60 s, `maxBodyBytes` 64 MiB decoded, `userAgentSuffix`; no total timeout),
  `ExportKind`, `ExportFormat`, `ExportWindow`, `ExportMeta`, `PriceRow`, `PriceList` (`get(name, paintIndex = '0')`,
  `allPhases(name)`), `NotModified`, `AffiliateLink`, `Errors\ExportException` (abstract) with
  `GeneralExportException` and `StaleExportException`. Statuses and network failures of an export GET are the usual
  `HttpException` / `TransportException`. Export GETs are retried like queries; redirects (max 3) are followed only to the
  same https host. The conditional cache (`ExportClient::create(cache:)`, `WhiteMarketClient::create(exportCache:)`)
  keeps `ETag` and `Last-Modified` only. `WhiteMarketClient::create()` / `fromEnv()` take `exportOptions` and
  `exportCache`.
- Exports part 2 (T5.2): `ExportClient::streamProducts(app, window, format, maxAgeSeconds)` -> `Generator<ProductRow>`,
  JSON (streamed with `halaxa/json-machine`, ADR 0004) or CSV (header matched by column name), one row in memory.
  New: `Export\ProductRow` (ids as strings, `Money` price, `ItemCategory` / `DeliveryType` / `DeliveryPromise` or
  `UnknownEnumValue`, `extra`). New runtime dependency: `halaxa/json-machine ^1.2.6`.
- Pluggable JSON parser of `streamProducts()` (ADR 0006): `Export\JsonStreamDecoder` (`Auto`, `JsonMachine`,
  `Simdjson`, `YajlStream`) and `ExportOptions::$jsonStreamDecoder` (default `Auto`: `ext-yajl_stream` when loaded,
  else `ext-simdjson`, else json-machine). Both extensions are optional (`suggest`);
  `JsonStreamDecoder::isAvailable()` tells whether a decoder can be chosen in this runtime. `streamProducts()` returns an
  `\Iterator<int, ProductRow>` (single pass); an object, a scalar or an empty body is a `DecodeException` for every
  decoder; a row over 128 KiB of text (JSON or CSV) is a `DecodeException` at its index for every decoder.
  A row whose `product_category` is null (the live file has a few) is skipped; iterator keys and error paths keep the file index, so keys may have gaps.
- `Transport\BodyReader` and `Transport\RetryLoop` (internal): the body reader (gzip, `Content-Length` check, bounded decode
  step) and the attempt loop `HttpTransport` and the export transport share.
  `RetryPolicy::shouldRetryRead()` (internal) is the retry decision of a GET that is not a GraphQL query.
- `Models\Decimal::tryOf(string): ?Decimal` (null instead of an exception for a value that is not a decimal
  string).
- Market module (SDK_SPEC 3.2), `$client->market` (`Market\MarketApi`): `search()` (one page; a `ProductFilter` or a raw
  `MarketProductSearchInput`), `iterate()` (lazy, cursor-driven), `get()`, `stickerSuggestions()`, `checkTradeUrl()`.
  New: `Market\ProductFilter`, `Market\ProductSort`, `Models\StickerSuggestion(s)`, high-level enums `App`, `Exterior`,
  `ItemCategory`, `ItemSubcategory`, `Phase`, `Rarity`, `StickerFilm`, `LogicOperand`, `Color`, `OfferType`,
  `ProductSortField`, `SortDirection`, and the `@internal` `Models\Paginator` shared by the later list modules.
- Buying module (SDK_SPEC 3.3, T3.3), `$client->buying` (`Buying\BuyingApi`): `buyProduct()`, `buyCheapest()`,
  `buyByAssetId()`. `market_buy` is sent once and never retried; after an ambiguous failure (5xx, network error,
  unreadable response) the SDK waits and looks the deal up by `customId` (`deal_item`, then `deal_history_item`).
  New: `Buying\BuyOptions` (`customId`, `tradeUrl`; a UUIDv4 `customId` is generated when omitted),
  `Buying\BuyResult` (`deal`, `customId`, `reconciled`), `Buying\BuyReconciliation` (`delay` 10-20 s, `attempts`,
  `interval`; `WhiteMarketClient::create(buyReconciliation:)` and `fromEnv(buyReconciliation:)`),
  `Errors\PurchaseOutcomeUnknownException` (`customId`, previous = the `market_buy` failure, `lastLookupError`).
- Deals module (SDK_SPEC 3.4, T3.4), `$client->deals` (`Deals\DealsApi`): `get()` (live deal, then archive; an id-shaped
  reference is tried as id, then as customId), `statuses()` (chunks of 300, keyed by the requested string),
  `listAsBuyer()`, `listAsSeller()`, `history()`, `iterateHistory()`, `requestCancel()`, `reportOfferSent()`,
  `cancelSendingOffer()` (comment validated: <= 255 chars of `a-zA-Z0-9 _-`), `holdBalance()`, `holdBalances()` and
  `waitFor()` (polls `statuses()`; with `live:` it is woken early by live deal events, see the live module). The three mutations are sent once and never retried; a
  UUID-shaped reference is resolved with queries first. New: `Deals\DealHistoryFilter`, `Deals\DealHistorySort`,
  `Deals\Until` (`offered`, `exchangedOrCompleted`, `completed`, `final`; each also matches a final status), enums
  `DealAction`, `DealHistoryStatus`, `DealHistorySortField`, `OfferCancelReason`, `Errors\DealWaitTimeoutException`.
- Inventory module (SDK_SPEC 3.6, T4.1), `$client->inventory` (`Inventory\InventoryApi`): `list()` (one page; an
  `InventoryFilter` or a raw `PersonInventorySearchInput`), `iterate()` (lazy, cursor-driven) and `refreshFromSteam()`
  (`inventory_update`: sent once, never retried; returns nothing). New:
  `Inventory\InventoryFilter`, `Market\ProductSort::toInput()` (internal).
- `Models\PageRequest` (`first`, `after`): the page argument of `market->search(filter, ?PageRequest $page)`.
- Project skeleton: Composer package `whitemarket/sdk`, toolchain, Makefile, CI.
- Generated layer `WhiteMarket\Sdk\Generated`: enums, input objects, typed result classes and `GeneratedClient` with one
  method per partner API operation plus `execute()` for raw documents; runtime in `WhiteMarket\Sdk\GraphQL`.
- Runtime support from PHP 8.2. PHP 8.1 is not supported: its engine corrupts memory when a generated decoder throws
  `DecodeException` (see ADR 0002 "Amendment 2026-10-31").
- GraphQL over HTTP: `GraphQL\GraphQLExecutor` (sends operations, maps `errors` to `GraphQLException` /
  `NotFoundException`), `Transport\HttpTransport` (SDK headers and User-Agent, query retry per `RetryPolicy`, never a
  mutation, `Retry-After`, status -> exception, redirects not followed, gzip, 32 MiB response limit counted after
  decompression, PSR-3 logging of operation name, attempt, status and duration only), `Transport\TransportOptions`,
  `Transport\GuzzleClientFactory` (default client: Guzzle, TLS verification always on); `GraphQLExecutor`,
  `HttpTransport`, `Auth\Credentials` and `Auth\StaticAccessToken` are `@internal`. New runtime dependencies: `guzzlehttp/guzzle`, `guzzlehttp/psr7`, `psr/http-client`,
  `psr/http-factory`, `psr/http-message`, `psr/log`, `ext-zlib`, `ext-curl` (ADR 0002).
- Access token lifecycle (ADR 0003): `Auth\TokenProvider`, `Auth\TokenStore`, `Auth\StoredToken`,
  `Auth\InMemoryTokenStore` (default) and `Auth\Psr16TokenStore` (`forPartnerToken()` derives one cache key per
  Partner Token). The Partner Token is exchanged with `auth_token`, the JWT is cached until 5 minutes before its `exp`
  (24 h when unreadable), and a 401 refreshes the token once and replays the request once, mutations included.
  New runtime dependency: `psr/simple-cache` (ADR 0003).
- `WhiteMarketClient` (SDK_SPEC 2): `create()` with exactly one of `partnerToken`, `accessToken` or `tokenProvider`,
  plus `tokenStore` (with `partnerToken` only), `TransportOptions`, an optional PSR-18 client with PSR-17 factories,
  `RetryPolicy` and a PSR-3 logger; `fromEnv()` reads `WHITEMARKET_PARTNER_TOKEN` and optional `WHITEMARKET_BASE_URL`.
- Models (SDK_SPEC 4, T3.1): `Models\Decimal` (exact decimal string, numeric `equals` / `compare`), `Money`,
  `Currency`, `Page`, `DealStatus` (group helpers `isInProgress`, `isExchanged`, `isSuccessful`, `isFailed`,
  `isReversed`, `isFinal`), `DealCancelReason`, `DealState`, `Deal` (in progress and archived in one model),
  `Product`, `ProductHistory`, `ProductStatus`, `InventoryItem`, `Sticker`, `DeliveryType`, `DeliveryPromise`. An enum
  value the server adds later arrives as `Models\UnknownEnumValue` with the raw value; an item type the SDK does not
  know maps to null. `Models\ModelMapper` is `@internal`.
  Without an injected client mutations go through a Guzzle client that never sends a request twice; an injected client
  is used as is and a PSR-3 notice says its timeouts are not the SDK's. `$client->auth` (`Auth\AuthApi`):
  `accessToken()`, `refresh()`, `ping()` (SDK_SPEC 3.1). `TransportOptions::withBaseUrl()`. Example
  `examples/auth_and_ping.php`.
- Selling module (SDK_SPEC 3.5, T3.5), `$client->selling` (`Selling\SellingApi`): `list()`, `listMany()`, `reprice()`,
  `repriceMany()`, `remove()`, `removeMany()`, `setLifetimeForAll()` (>= 600 s), `myProducts()`, `iterateMyProducts()`.
  Every mutation is sent at most once and never retried; an ambiguous failure of a single mutation propagates as is
  (no idempotency key, no reconciliation: check `myProducts()`). The `*Many` methods send one request per chunk
  (`WhiteMarketClient::create(sellingBatchSize:)`, default 100) and return one `BatchItemResult` per item in the
  caller's order, or throw `BatchIncompleteException` with the outcome of every item. An item is a UUID (inventory
  or listing id) or digits (64-bit Steam asset id, kept as a string). New: `Selling\ListingOptions` (`AUTO` rejected,
  lifetime 60..31536000), `Selling\Listing`, `Selling\PriceChange`, `Selling\BatchItemResult`,
  `Selling\BatchOutcome` (`Succeeded`, `Failed`, `Unknown`, `NotSent`), `Errors\BatchIncompleteException`.
- Profile module (SDK_SPEC 3.9, T4.4), `$client->profile` (`Profile\ProfileApi`): `id()`, `get()`, `setTradeUrl()`,
  `steamProxy()`, `setSteamProxy()`, `deleteSteamProxy()`, `setSteamToken()`, `checkSteamToken()`,
  `deleteSteamToken()`, `setEmail()`, `confirmEmail()`, `resendEmailConfirmation()`, `linkReferrer()`,
  `changeReferrerCode()`. Every mutation is sent once and never retried. The Steam proxy and the Steam token payload
  are `Auth\Secret` parameters, `steamProxy()` and `Profile::$steamTradeUrl` return a `Secret`. New: `Profile\Profile`,
  `Profile\TradingCompetitionStat`, high-level enum `Models\Locale` (proposal P16).
- Store module (SDK_SPEC 3.9, T4.5), `$client->store` (`Store\StoreApi`): `get()` (`store_my`, retried like any query)
  and `edit(StoreSettings)` (`store_edit`, sent once and never retried). New: `Store\Store`, `Store\Achievement`,
  `Store\AchievementParam`, `Store\StoreSettings` (all parameters optional; `customAvatar` is a base64 image sent as
  `encodedCustomAvatar`, the multipart upload is not supported), high-level enums `Models\StoreAvatar`,
  `StoreBanner`, `AchievementName`, `AchievementStatus`, `AchievementKind`, `AchievementCondition` (proposal P17).
  `Achievement::$kind` is the wire `type`; `AchievementParam::$name` is an `AchievementCondition` with the unknown
  fallback; the `StoreSettings` flags are named like those of `Store` (`isFavoritesPublic`, ...).
- Wallet module (SDK_SPEC 3.8, T4.3), `$client->wallet` (`Wallet\WalletApi`): `balance()`, `balances()`, `deposits()`,
  `withdrawals()`, `transactions()` / `iterateTransactions()` (a `WalletTransactionFilter` or a raw
  `WalletTransactionSearchInput`), `transfers()` / `iterateTransfers()`, `credit()` (one query document with the three
  `wallet_credit_*` root fields, retried like any query), `takeCredit()` and `transfer()`. The two money mutations are
  sent once and never retried and have no idempotency key: a failure that proves the server did not run them
  propagates as is, any other failure throws `Errors\MutationOutcomeUnknownException` (check `wallet.credit()` /
  `wallet.transfers()` before sending again). New: `Wallet\Deposit`, `Withdrawal`, `WalletTransaction`,
  `WalletTransfer`, `PersonSummary`, `Debt`, `Credit`, `WalletTransactionSort`, `WalletTransactionFilter`, and high-level
  enums `TransactionReason`, `TransactionStatus`, `TransactionFeeType`, `TransferStatus`, `DepositStatus`,
  `WithdrawalStatus`, `PaymentProvider`, `WalletMethod`, `CryptoCurrency`, `CryptoNetwork`, `TransactionSortField`.

### Changed

- New direct runtime dependency `phrity/net-stream ^2.4` (together with `phrity/websocket ^4.1`, ADR 0005) for the
  WebSocket live transport.

- `Models\WireEncoder` (internal) now refuses an enum value the API schema lacks (`DealStatus::Queued`) with an
  `\InvalidArgumentException` naming the enum and the value, instead of a `\ValueError` from `from()`. Callers of
  `ProductFilter` / `BuyingApi` / `DealsApi` see `InvalidArgumentException` for such a value.
- A 429 or 503 response with `Retry-After` is no longer retried: the query fails at once with `RateLimitException` /
  `ServiceUnavailableException` carrying `retryAfter`, and the caller decides when to retry (SDK_SPEC 1.4).
  `RetryPolicy::$maxRetryAfterSeconds` is removed. Without `Retry-After` queries are retried with backoff as before.
- Queries are also retried on HTTP 500 (SDK_SPEC 1.4); mutations still never.
- `Retry-After` also accepts the obsolete RFC 850 and asctime HTTP-date formats (RFC 9110 5.6.7); before, such a
  header was treated as missing, so a 429/503 query was retried and the exception carried `retryAfter = null`.

- Value objects `GraphQLErrorEntry`, `UnknownEnumValue`, `UnknownTypeValue` and `RetryPolicy` are `final readonly class`
  (PHP 8.2 is the minimum); their properties were already readonly, so reading code is unaffected.

### Security

- The `schema-sync` workflow runs a `repository_dispatch` only for the `partner-schema-changed` event type sent by a
  login listed in the repository variable `SCHEMA_SYNC_DISPATCH_SENDERS` (JSON array); unset means no dispatch runs.
- New `release` workflow (tag `v*.*.*`): `make check`, source archive from `git archive`, build provenance attestation
  (`actions/attest`) and a GitHub Release, with least-privilege permissions per job.
- `TransportOptions` and `ExportOptions` reject a `baseUrl` with credentials (`https://user:pass@host`); previously it was accepted.
- `HttpException::$bodyExcerpt` is safe to log: values under JSON keys holding a credential marker (token, secret,
  password, cookie and similar words, `Bearer`, `Basic`, a JWT, `cf_connect`) are masked, keys and any other text are
  cut after the first marker, also when the marker only appears after JSON unescaping, and the excerpt is valid UTF-8
  of at most 512 bytes. A credential whose marker sits only in a sibling value (`{"name":"Cookie","value":"..."}`)
  is not detected.
- Building `HttpException::$bodyExcerpt` no longer passes a malformed or non-JSON body to `json_decode()`, so neither
  a caught `JsonException` (seen by exception hooks) nor an out-of-memory backtrace (PHP 8.5 `fatal_error_backtraces`)
  carries the raw body. A JSON body holding an escaped NUL (`\u0000`) is now redacted as text, not re-encoded.
  Remaining: running out of memory while decoding valid JSON of up to 1 MiB still shows the
  first bytes of the body (15 by default) in the backtrace; on PHP 8.2 a malformed body that starts with `{` or `[`
  is still decoded.
- `Secret` keeps its value out of every dump: `(array)` casts, `get_mangled_object_vars()`, reflection-based dumpers
  such as Symfony VarDumper, and backtraces taken while it is being constructed.
- Generated credential fields are `Secret`, not `string`: `AuthTokensFields::$accessToken`,
  `WalletDeposits\Result::$airwallexSecret` (read the value with `reveal()`).
- The raw HTTP body (`HttpException`) and every argument of a generated operation method are marked
  `#[\SensitiveParameter]`, so a Steam token payload, a JWT or a response body never shows in exception traces.
- A `DecodeException` raised by a malformed field no longer carries the rest of the response (an access token next to
  the broken field) in its trace arguments: response data and operation variables are `#[\SensitiveParameter]` in
  every decoder, encoder and generated `decode()` / `fromValue()`. A PHPStan rule requires the attribute on every
  implementation of a parameter its interface or parent marks, since PHP does not inherit it (variadic parameters
  included); a second rule forbids internal callback functions (`array_map`, `preg_replace_callback`, ...) in methods
  with a sensitive parameter, whose frames would keep the raw value.
- `Profile::$steamTradeUrl` and `Deposit::$airwallexSecret` (promoted `?Secret` constructor parameters) are
  `#[\SensitiveParameter]`. A third PHPStan rule requires the attribute on every parameter typed `Secret` (nullable
  and union types included) or as an SDK class whose constructor has a sensitive parameter (`BuyOptions`); generated
  code and unit tests are not checked.
- `GraphQLException` (`errors`, `partialData`) and `GraphQLErrorEntry` (`message`, `category`, `extensions`) keep the
  server response out of exception trace arguments; encoding a list of variables and unescaping an HTTP error body no
  longer expose them through an internal `array_map()` / `preg_replace_callback()` frame when something throws inside.
- A `DecodeException` for a malformed date-time names only the length of the value, not the value itself.
- A credential header value or name with a trailing line feed (a token read from a file or an environment variable)
  is rejected by the SDK before the request is built; previously the PSR-7 implementation rejected it with the full
  value in its exception message. `TransportOptions` likewise rejects `baseUrl`, `graphqlPath` and `userAgentSuffix`
  ending in a line feed. If a PSR-7 implementation rejects a header the SDK accepted, the SDK throws its own
  `InvalidArgumentException` without the value and without chaining the original.
- `TransportException` traces no longer carry the HTTP client exception (and with it the request and its
  `Authorization` header) as a trace argument. Anything else the client throws (a non-PSR-18 exception or an `Error`
  from a custom client or middleware) is also turned into an unchained `TransportException`, and a rejected credential
  header no longer exposes the headers set before it, whatever the PSR-7 implementation throws.
- The trade URL of a failed purchase and the recipient of a failed `wallet.transfer()` no longer show through a trace
  argument that holds the original failure (its own trace reaches the caller's frame), and a client that keeps the
  requests it sent no longer exposes them, credential headers included, through the retry loop's closures in a
  `print_r` of an exception trace.
- Mutations are sent with `GuzzleClientFactory::createForMutations()`: a fresh connection per request, closed
  afterwards, with a body Guzzle cannot rewind. With a reused keep-alive connection that the server closed after
  reading the request, libcurl resent the mutation by itself; with a server that sent only a status line and closed,
  Guzzle resent it up to 3 times; either way it could run more than once. `HttpTransport` takes the mutation client as `mutationClient`; an injected
  PSR-18 client used for mutations must not resend requests on its own.
