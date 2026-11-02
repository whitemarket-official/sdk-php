# SDK_SPEC change proposals from the PHP reference SDK (2026-10-30)

Status: P1-P3 and P5 approved by the owner on 2026-10-31. P1, P2 and P5 are applied to `docs/SDK_SPEC.md` in this repo
and copied byte-for-byte to the six sister repos (session 5, not committed there). P4 is on hold: the cause is a backend config gap (the order implementations are missing from the
`partner` schema's `types` list), fixed in the backend instead (plan "Deviations", P1 finding).
`docs/SDK_SPEC.md` is shared by all seven SDKs (CLAUDE.md rule 18); every applied edit goes to every sister repo.

## P1. Section 1.3 Transport: PHP default client

- Before: "PHP PSR-18 + PSR-17 (discovery via `php-http/discovery`)"
- After: "PHP PSR-18 + PSR-17, default Guzzle (`guzzlehttp/guzzle ^7.9 || ^8.0` + `guzzlehttp/psr7`) with preconfigured
  timeouts; any PSR-18 client can be injected (then the SDK cannot set timeouts and logs one notice)"
- Why: owner requirement 2 in ADR 0002 (transport on Guzzle with preconfigured defaults and a clear way to replace it).
  Discovery picks whatever client happens to be installed, so timeouts and size limits cannot be guaranteed.
- Other languages: no change.

## P2. Section 1.2 Operation generator: fragment rule

- Before: "Every object type that is selected in more than one place gets a named fragment `<Type>Fields` in
  `graphql/fragments/<Type>.graphql`."
- After: "Every object type that is selected in more than one place gets a named fragment `<Type>Fields` in
  `graphql/fragments/<Type>.graphql` holding only the type's non-deprecated scalar and enum fields. Object fields stay inline
  next to the spread, because the same type is selected at different depths and a fragment cannot vary by depth."
- Why: implemented this way in `tools/codegen/generate-operations.mjs` (plan "Deviations", T1.2). The spec text as written
  cannot be satisfied: a fragment with nested objects would exceed the depth limit `D` in deeper positions.
- Other languages: none in code (the script is shared byte-for-byte); the text must match what the script does.

## P3. Section 0: supported runtimes, PHP exception

- Before: "Every language version still supported upstream at release time, nothing older."
- After: append "Exception: PHP supports 8.1+ (owner decision, ADR 0002 of the PHP repo), although 8.1 is past upstream
  security support."
- Why: owner requirement 4 in ADR 0002 (lowest possible PHP). Recorded in `docs/DEVIATIONS.md` until the spec says so.
- Other languages: no change.
- Withdrawn 2026-10-31: the PHP SDK now requires 8.2 (ADR 0002 "Amendment 2026-10-31"), so the exception was removed
  from this repo's `docs/SDK_SPEC.md`. Sister repos never received P3.

## P4. Section 1.2 Operation generator: interfaces without implementations

- Before: "Interfaces and unions: `__typename` plus `... on <Type> { ... }` for every possible type, in schema order."
- After: append "For an interface, also select the interface's own fields (same rules) next to `__typename`, so the
  fields are read even when the schema exposes no implementation."
- Why: the public partner schema has no type implementing `MarketOrder` or `MarketOrderPublic`. Every order operation
  (`order_new`, `order_edit`, `order_activate/deactivate/delete`, `order_list`, `order_my*`) now selects `__typename`
  only, so an SDK cannot return the order id, price or quantity. Found while generating the PHP layer (P1).
- Other languages: the shared Node script changes, so `graphql/operations/**` and `CHECKSUMS` change in every repo at
  once; each generated layer then gets typed interface fields.

## P5. Section 1.5 Errors: the HttpError body excerpt is redacted

Status: approved by the owner 2026-10-31 (session 5) and applied to `docs/SDK_SPEC.md` section 1.5 in this repo and
all sister repos. The "After" text below is the first draft; the spec text supersedes it (escape-aware marker search,
masking under any marker key, the owner's option C: sibling-value markers are out of scope).

- Before: "`HttpError(status, body excerpt)`", no rule for the excerpt's content.
- After: append "The body excerpt is at most 512 bytes of valid UTF-8 (invalid bytes become U+FFFD) and safe to log.
  A JSON body is redacted by structure: every value under a key containing `token`, `secret`, `passw`,
  `authorization`, `cookie`, `api_key`/`apikey`, `jwt`, `credential` or `session` (any case) becomes `***`, every other
  string value is redacted as text, and the result is re-encoded compactly. Any other body (not JSON, deeper than 64,
  larger than 1 MiB) is redacted as text. Text is cut after its first credential marker (the key words above, `bearer`,
  `basic`, `eyJ`, `cf_connect`, any case) and `***` is appended."
- Why: an error body can echo a request header or token, and the excerpt is the one part of an error meant for logs
  (SDK_SPEC 1.3, 8). Found by the whole-tree security review (REDACT-1). Two pattern-matching versions were bypassed
  by an adversarial review (`\u` escapes in keys, `\/` in values, JWTs crossing the cut, quoted `key="value"`,
  double-encoded JSON, deep nesting); cutting at the marker does not depend on the value's format.
- Other languages: each SDK implements the same rules and the same test cases (`tests/unit/Errors/BodyRedactorTest.php`).

## P6. Section 8 Security: credential fields and error attachments

Approved by the owner 2026-10-31 (session 6) and applied to all seven `docs/SDK_SPEC.md` copies.

- Change: credential fields of responses decode into the secret type; errors carry no credential in attached data,
  including stack-trace arguments where the runtime records them.
- Why: the PHP SDK's security review found the plain `accessToken` / `airwallexSecret` strings and a `DecodeException`
  whose trace arguments held the whole response (token included) when a sibling field was malformed.
- Other languages: map the field names to the secret type in the generator; check (against that runtime's docs) what
  it attaches to errors, e.g. frame locals or arguments, and keep the response out of `DecodeError`.

## P7. Section 1.4 Retry policy: the HTTP client below the SDK must not resend a mutation

Status: approved by the owner 2026-10-31 (session 10) and applied to `docs/SDK_SPEC.md` section 1.4 in this repo
(session 11); sister repos are synced by the owner. Implemented in the PHP SDK (ADR 0002 "Implementation notes", T2.4).

- Before: "Mutations are never retried" covers the SDK's own retry loop only.
- After: append "This includes resends by the HTTP client library: a mutation is sent on a connection that is not
  reused and is closed afterwards, unless the library documents that it never resends a request on its own."
- Why: libcurl resends a request by itself when a reused keep-alive connection dies before any response byte
  (curl `lib/transfer.c`, `Curl_retry_request`). A local server that read a mutation and then closed the idle
  connection received it twice through Guzzle with one SDK call (`GuzzleClientFactoryTest`). Cost: one TCP + TLS
  handshake per mutation. Guzzle adds a resend of its own (up to 3 times when libcurl ends without an error code and
  without a response, e.g. a server that sends only a status line and closes); the PHP SDK stops it with a body that
  cannot be rewound.
- Other languages: check the default client's resend behaviour against its own docs (not verified here for any
  client but libcurl/Guzzle) and reproduce with a local server that drops the second request on a kept-alive
  connection.

## P8. Section 2 Configuration: credential options are exclusive; an injected HTTP client is used as is

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 2 (was: proposed (session 13, T2.6); implemented in the PHP SDK (`WhiteMarketClient::create()`)).

- Before: section 2 lists `partnerToken?`, `accessToken?`, `tokenProvider?`, `tokenStore?` and `httpClient` without
  saying how they combine.
- After: append "Exactly one of `partnerToken`, `accessToken`, `tokenProvider` is given, otherwise construction fails;
  `tokenStore` is accepted with `partnerToken` only (a caller's `tokenProvider` owns its storage, a fixed
  `accessToken` has nothing to store). An injected `httpClient` is used as is for queries and mutations: the SDK
  cannot set its timeouts and logs one notice saying so; the caller is responsible for it not resending requests
  (section 1.4). `fromEnv()` lets `WHITEMARKET_BASE_URL` replace only the base URL of the other options."
- Why: a silently ignored option is a misconfiguration the user never sees: a `tokenStore` passed with `accessToken`
  would look shared between workers but hold nothing; two credentials leave which one wins to the implementation.
- Other languages: same checks at construction, error type of the language's argument validation.

## P9. Section 4 Models: field sets, unknown enum values, DealStatus extras

Status: approved by the owner 2026-11-01; implemented in the PHP SDK (`src/Models/`, `Models\UnknownEnumValue`).

- Before: section 4 names the models but not their fields; "Enums: native enums with an unknown fallback" does not
  say what the fallback carries.
- After: add the field sets below and "An enum field the server may extend is typed `Enum | Unknown{enum, value}`
  (PHP: `Enum|UnknownEnumValue`), never a catch-all case: the raw value stays readable. High-level enums are matched
  to the wire by value, so they may list a value the schema snapshot lacks (`DealStatus.QUEUED`, treated as in
  progress per WHITEMARKET_API.md 4). An enum value that exists in the schema (`DealCancelReason.UNKNOWN`) is a
  normal case."
  - `Money {amount: Decimal, currency}`; `Decimal` keeps the server string verbatim (`"114.300"`), `equals` /
    `compare` are numeric (`"1.50" == "1.5"`, `"-0" == "0"`), accepts `-?\d+(\.\d+)?` only.
  - `Page<T> {items, endCursor, hasNextPage, totalCount?}`.
  - `InventoryItem {id, assetId, classId, instanceId, nameHash?, name?, float?: Decimal, paintSeed?, paintIndex?,
    phase?, inspectLink?, isStatTrak?, isSouvenir?, stickers: [Sticker {name, title, wear?: Decimal}]}`; Steam ids
    are strings; `name`, `isStatTrak`, `isSouvenir` come from the Steam description and are null without one; null
    entries of the sticker list are skipped. An item or description of a type the SDK does not know maps to null.
  - `Product {id, slug, price, createdAt, isOwner, deliveryType, deliveryPromise, item?}`.
  - `ProductHistory {id, status: ProductStatus, price, archivedAt, deliveryType, assetId, classId, instanceId,
    item?}` (item from `params`, described by the product's `description`).
  - `Deal {id, customId?, status, price, createdAt, deliveryType, steamId?, tradeId?, isTradeProtected?,
    settlementTimeAt?, expiresAt?, isCancelByBuyerSupported, cancelByBuyerNeedWait?, cancelReason?, archivedAt?,
    reversePenalty?, reverseRefund?, product: ProductHistory, item?}`, `isArchived = archivedAt != null`. Fields of
    one side only (in progress: `tradeId`, `isTradeProtected`, `settlementTimeAt`, `expiresAt`,
    `cancelByBuyerNeedWait`, `item`; archived: `cancelReason`, `archivedAt`, `reverse*`) are null on the other;
    `isCancelByBuyerSupported` is false for an archived deal.
  - `DealState` = `MarketDealState` fields with `reversePenaltyValue` / `reverseRefundValue` named `reversePenalty` /
    `reverseRefund`; `isTradeProtected` stays nullable as in the schema.
  - `DealStatus` helpers per WHITEMARKET_API.md 4: exactly one of `isInProgress`, `isExchanged`, `isSuccessful`,
    `isFailed`, `isReversed` holds for a known status; `isFinal = successful | failed | reversed` (`EXPIRING` counts
    as failed and final); an unknown status is in no group. `awaitingTradeProtection` is on `Deal` / `DealState`.
- Why: the sister SDKs need one field list to stay at parity; the fallback shape decides whether users can log or
  branch on a new value.
- Not in v0.1 (non-breaking to add): `fade` (a binary float in the schema), exterior / category enums, store and
  person data, charms.

## P10. Section 3.3 buy reconciliation: gaps filled by the PHP SDK

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.3 (was: proposed (session 15, T3.3); implemented in the PHP SDK (`src/Buying/`)).

- Before: 3.3 says to reconcile "after an ambiguous failure" and to look the deal up by `customId`, but not which
  failures are ambiguous, what a failed lookup means, whether an empty `customId` is valid, or whether the loop sleeps
  after the last attempt.
- After: add the rules below to 3.3.
  1. Which failures reconcile: every failure of `market_buy` except `GraphQLError` (the deal was not created), 400,
     401 (after the transport's one refresh and replay) and 429. Explicitly included: `DecodeError` (a 2xx with an
     unreadable body, or a deal that cannot be mapped), every other `HttpError` (5xx incl. 503, unexpected statuses)
     and `TransportError`, without telling a failure before sending from one after it. An extra lookup is cheap; a
     wrong "no deal" causes a double purchase.
  2. A failed lookup (any error, not only `NotFoundError`) counts as "not found in this attempt". The last lookup error
     is kept on `PurchaseOutcomeUnknownError.lastLookupError`; the `market_buy` failure is the cause. A failing lookup
     never turns into "no deal".
  3. `customId` given by the caller must be 1..100 characters; the empty string is rejected client-side (a lookup by an
     empty id is meaningless).
  4. The spec loop sleeps `interval` after the last attempt too; the PHP SDK follows it literally. Proposal: drop the
     trailing sleep (saves `interval` on the failure path).
  5. The not-found category of a deal lookup is unknown (WHITEMARKET_API.md open question 6); rule 2 makes the SDK
     independent of it.
  6. `buyCheapest` opts: PHP takes `maxPrice`, `phase`, `deliveryPromise` as named arguments plus the shared
     `BuyOptions` (customId, tradeUrl); proposal: the spec names this split so sister SDKs mirror it.
  7. SDK_SPEC 3.3 'GraphQLError -> throw' assumes the error rejected the mutation. A 200 with errors on a nested path,
     or with a non-null `market_buy` in `data`, means the purchase ran: the PHP SDK reconciles those. A rejection is: no
     `market_buy` data and every error path empty or exactly `[market_buy]`. Found by adversarial verification (session
     15). Residual: a root-path error raised after the server committed the deal still reads as a rejection; telling
     those apart needs the backend's category catalogue (WHITEMARKET_API.md open questions).
- Why: the sister SDKs need the same decisions, or the same outage ends in a retry in one language and an error in
  another; the money-affecting choice (reconcile rather than assume failure) must not differ.

## P11. Section 3.4 deals: gaps filled by the PHP SDK

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.4 (was: proposed (session 16, T3.4); implemented in the PHP SDK (`src/Deals/`)).

- Before: 3.4 says `deals.get(idOrCustomId)` falls back from `deal_item` to `deal_history_item`, and names the
  `waitFor` options and the `until` predicates, but not how an id is told from a customId, how mutations that take
  either avoid a double send, what `statuses` returns for unknown or repeated ids, which error a timeout raises, or
  what the predicates do on a final status.
- After: add the rules below to 3.4.
  1. Reference resolution for queries: a string in UUID form is looked up as `id` first, then as `customId` (a
     customId is often a UUID: the SDK generates UUIDv4 ones); any other string only as `customId` (sending it as
     `id: Uuid` is a validation error). Each key tries `deal_item`, then `deal_history_item` on not-found; a failure
     that is not a not-found propagates at once; when every key is not found the last not-found is thrown. Empty is
     rejected client-side.
  2. Mutations that take `idOrCustomId` (`reportOfferSent`, `cancelSendingOffer`) never "try id, then customId" (rule:
     mutations are sent once). A non-UUID reference is sent as `customId`. A UUID-shaped one is resolved with `get()`
     first (queries) and the mutation is sent once with the `id` found; a not-found there means nothing was sent.
  3. `statuses(ids)` returns a map keyed by the requested string, in request order; ids the server does not return are
     absent (no error); a duplicate is sent once and returned once; a state matches a key when its `id` or `customId`
     equals it. Chunking by 300 is applied after the deduplication.
  4. `waitFor` raises `DealWaitTimeoutError(idOrCustomId, lastState, timeout)` (not in 1.5). Default timeout: none.
     A deal the server does not report yet counts as "not yet" (a new deal needs a moment to become visible). The first
     poll is immediate; a timeout of 0 polls exactly once; `pollInterval` must be finite and > 0, `timeout` null or
     finite and >= 0. Elapsed time is wall-clock. Waking up on live events (`opts.live`) is deferred to the live module.
  5. Every `until` predicate also matches a final status (`isFinal`), so a wait never outlives a failed or reversed deal
     and the caller checks the status of the returned state. Consequence: `completed` and `final` match the same states
     (`COMPLETED` is final). Proposal: define `completed` as "final" in the spec, or drop one of them.
  6. `QUEUED` is not in the public schema: it can be read (`DealStatus::Queued`) but not sent in a status filter; the
     SDK rejects it client-side before any request.
  7. A requested key matches a state when it is UUID-shaped and equals the state's `id` without regard to case (RFC
     9562), or equals the state's `customId` exactly (a customId is a free-form `String`, WHITEMARKET_API.md 4: folding
     its case could attribute one deal's state to another whose customId differs only in case). Deduplication for
     sending is exact: two spellings of one UUID are both sent and the server decides; each requested spelling is a key
     of the result. A mutation by `idOrCustomId` accepts the resolved deal when its `id` equals the UUID-shaped
     reference case-insensitively or its `customId` equals it exactly.
  8. `waitFor` caps the last sleep at the remaining timeout and polls once more at the deadline before raising. The
     timeout bounds the waiting between polls; a poll already in flight (its query retries per SDK_SPEC 1.4 and the
     request timeout) may finish after it, so the call can return or throw up to one poll's duration after the timeout.
  9. `requestCancel` accepts a deal id (UUID) only; anything else is rejected client-side before any request.
  10. A mutation by `idOrCustomId` checks that the resolved deal matches the reference (`id` or `customId`,
     case-insensitive for the UUID form) before sending; a mismatch is a decode error and nothing is sent.
- Why: the sister SDKs need the same lookup order and the same "mutation once" rule, or one of them sends a purchase
  related mutation twice and another does not.

## P12. Section 3.5 selling: gaps filled by the PHP SDK

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.5 (was: proposed (T3.5); implemented in the PHP SDK (`src/Selling/`)).

- Before: 3.5 lists the methods, the `lifetime` bounds, the AUTO rejection and "chunked, results re-indexed to the
  caller's order", but not how an item is named, what a failed single mutation means, what a batch returns when a
  request fails half way, or which field says where a result belongs.
- After: add the rules below to 3.5.
  1. Item reference by format: a UUID is an inventory id (`list`, `listMany`) or a listing id (`repriceMany`); digits
     (1..20, a 64-bit Steam asset id kept as a string) are an asset id. Anything else is rejected client-side before
     any request. `reprice`, `remove` and `removeMany` take listing ids (UUID) only.
  2. The single selling mutations (`market_new`, `market_edit`, `market_remove`, `market_lifetime_all`) have no
     idempotency key: each is sent once, never retried, and an ambiguous failure (5xx, network error, unreadable
     response) propagates as is, documented as "the outcome is unknown; check `myProducts()`". No reconciliation.
  3. Batch result model: `BatchItemResult{index (position in the caller's list), outcome Succeeded|Failed|Unknown|NotSent,
     product, errorCategory, errorMessage}`. `Succeeded`/`Failed` are the server's `isCreated`. A request (chunk) that
     fails stops the batch: its items are `Unknown` (ambiguous failure) or `NotSent` (the server rejected it before
     running it: the classification of P10 point 7, shared), later chunks are `NotSent` and never sent, earlier chunks
     keep their results, and the call raises `BatchIncompleteError(results)` with `previous` = the failure. An item
     the server leaves out of its answer is `Unknown`; the remaining chunks still go out and the error is raised at
     the end. The call returns normally only when every item has a server-reported outcome. A product that cannot be
     mapped leaves `product` empty and does not change the outcome.
  4. `MarketProductResult.index` is read as the 0-based position within the chunk (schema doc: "Position in request").
     Not confirmed by the backend: question for the backend, together with the maximum batch size. An `index` outside
     the chunk or repeated cannot be matched; the mutation already ran, so the chunk is `Unknown`.
  5. The chunk size defaults to 100 and is configurable at client creation (`create(sellingBatchSize:)`, at least 1).
  6. `ListingOptions` rejects `AUTO` and a `lifetime` outside 60..31536000 at construction; `setLifetimeForAll`
     rejects less than 600. `deliveryType` is always sent (default `SEMI`).
  7. A batch GraphQL error is a rejection only when every error has no path (a request error); an error at the root
     field means execution started, so the chunk is `Unknown` (single `market_buy` keeps P10 point 7).
  8. Rows are cross-checked against the requested item where the product carries its identity; a mismatch makes the
     chunk `Unknown`.
  9. A batch GraphQL error is a rejection only when the response has no `data` key (GraphQL spec 7.1.2: request errors
     omit `data`); `data: null` means execution started -> `Unknown`.
     Residual: a 2xx other than 200 (202, 204) with errors and no `data` is also read as a request error; the API
     documents only 200 for success (WHITEMARKET_API.md 2).
- Why: the sister SDKs need the same batch semantics, or one of them resends a chunk that already ran (a duplicate
  listing) and another reports it as lost.

## P13. Section 3.6 inventory: gaps filled by the PHP SDK

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.6 (was: proposed (T4.1); implemented in the PHP SDK (`src/Inventory/`)).

- Before: 3.6 lists `list`, `iterate` and `refreshFromSteam`, but not what the refresh returns, which sort the filter
  takes, or what happens to inventory items of another game.
- After: add the rules below to 3.6.
  1. `refreshFromSteam()` returns nothing for now. The API returns the full person; a later version may return the
     profile model once `profile.get()` exists (widening `void` is backward compatible for callers).
  2. `InventoryFilter.sort` reuses the market product sort (the API input type is the same `MarketProductSortInput`).
  3. Non-CS2 inventory items (unknown `__typename`) are skipped in pages, like product items.
- Why: the sister SDKs need the same behaviour, or one of them exposes a half-model of the person and another fails
  on an item it cannot map.

## P14. Section 1.2 operation generator: interfaces without possible types

Status: obsolete (2026-11-02). The backend added the implementations (`CSGOMarketOrder`, `CSGOMarketOrderPublic`) and
the schema sync of 2026-11-02 brought them in, so the existing inline-fragment rule selects the order fields; no
generator change is needed. T4.2 (orders) is unblocked.

- Before: "Interfaces and unions: `__typename` plus `... on <Type> { ... }` for every possible type". The public
  partner schema declares `MarketOrder` and `MarketOrderPublic` as interfaces with no implementing type
  (`schema/partner.introspection.json`: `possibleTypes: []`), so every `order_*` operation selects only `__typename`
  and returns no order data.
- After: "An interface with no possible type in the schema is selected like an object type: `__typename` plus its own
  non-deprecated fields by the object rules (depth, fragments, required-argument skip). An interface with possible
  types keeps the inline-fragment rule." Fragments for such an interface are named `<Interface>Fields` like object
  fragments.
- Alternative (no spec change): per-repo hand-written overrides in `graphql/overrides/` for the 13 `order_*`
  operations, repeated in every language, plus each language's codegen supporting fields selected directly on an
  interface type.
- Why: the server returns its internal implementing type at run time; selecting the interface fields directly is
  valid GraphQL and keeps the generator the single source of the selection in all seven SDKs. Open question for the
  backend: which `__typename` the server returns for these values (the SDK maps by fields, not by the name).

## P15. Section 3.8 wallet: gaps filled by the PHP SDK

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.8 (was: proposed (T4.3); implemented in the PHP SDK (`src/Wallet/`)).

- Before: 3.8 lists the wallet methods, "three fields in one document" for `credit()`, and nothing about the failure of
  `transfer` / `takeCredit`, which move money without an idempotency key.
- After: add the rules below to 3.8 (and the error to 1.5).
  1. `credit()` is one raw query document with the three root fields (typed per-operation overrides hold one root
     field), so it is a query and is retried as one.
  2. `transfer` and `takeCredit` are sent once. A clean rejection (HTTP 400/401/429, a GraphQL error of the root field
     with `data` null) propagates as its own error. Any other failure is `MutationOutcomeUnknownError(operation)`, so a
     caller never mistakes it for a rejection and sends money twice; its message names the method and where to check
     (`wallet.transfers()` / `wallet.credit()`) and carries no amount or id. Proposal: use the same error for every
     non-idempotent single mutation (selling, deals, orders) in a later revision.
  3. `transfer(to, amount)` takes a person id or a Steam id unchanged (the format is undocumented); an empty value or
     one with control characters is rejected before any request.
  4. Names: models `Deposit`, `Withdrawal`, `WalletTransaction`, `WalletTransfer`, `Debt`, `Credit`, `PersonSummary`;
     enums as in the schema in the language's idiom (`WalletMethodEnum` is `WalletMethod`, `MarketDealActionType` is
     the existing `DealAction`); the filter and the sort are `WalletTransactionFilter` and `WalletTransactionSort`.
  5. A root-path or pathless GraphQL error with `data: null` on `transfer` / `takeCredit` is read as a rejection (as
     `market_buy`, P10 point 7), so a server that fails after committing reports a clean error; telling business
     rejections from post-commit failures needs the backend's category catalogue (WHITEMARKET_API.md open question 1).
- Why: the sister SDKs need the same behaviour, or one of them resends a transfer or hides a possibly executed one.

## P16. Section 3.9 profile: gaps filled by the PHP SDK

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.9 (was: proposed (T4.4); implemented in the PHP SDK (`src/Profile/`)).

- Before: 3.9 lists the profile methods and says the Steam token payload and proxy credentials are secrets, but not
  which signatures carry them, what `checkSteamToken()` returns, or what `ttl` means.
- After: add the rules below to 3.9.
  1. Secrets: the proxy and the token payload are taken as the language's secret type (`setSteamProxy(proxy: Secret,
     ttl)`, `setSteamToken(payload: Secret, ownProxyRequired, ttl)`), the parameters are marked sensitive, and the plain
     value is revealed only when the request body is built. `steamProxy()` returns a secret (or null for null / empty)
     and `Profile.steamTradeUrl` is a secret (null for null / empty): the trade URL carries the trade token.
     `setTradeUrl(url)` takes a plain string with a sensitive parameter.
  2. `checkSteamToken()` returns the server's Int unchanged (nullable); the API does not document its meaning.
  3. `ttl` is passed through unchanged and not validated on the client; the API documents no unit.
  4. `ownProxyRequired` defaults to false and is always sent (the server default is false, so this is equivalent).
  5. Every profile mutation is sent once and never retried; failures propagate as they are (no money moves, so no
     "outcome unknown" error). `get()`, `id()` and `steamProxy()` are queries and follow the retry table.
  6. `Profile` fields: id, registeredAt, locale, email, isEmailConfirmed, canBuy, canSell, canDeposit, canWithdraw,
     isKycVerified, hasPartnerToken, ordersAutoActivate, deliveryPromise (the existing `DeliveryPromise`),
     steamId, steamName, steamLevel, steamAvatarMedium, steamAvatarFull, steamRegisteredAt, steamTradeUrl,
     inventoryUpdatedAt, discountPercent, discountActiveTo, sellBannedUntil, vacationUntil, level, points, leftPoints,
     percent (string as sent: the format is undocumented), referralsCount, inviterCode, referrerCode, referralTag,
     countryCodeOfResidence, countryCodeOfCurrentLocation, isAchievementsEnabled, isSkinTranslationEnabled,
     isMcBattlePassEnabled, tradingCompetitionStat (position, topLevel). `setEmail`, `confirmEmail` and
     `resendEmailConfirmation` return the same `Profile`. `Locale` is a new enum with an unknown fallback.
- Why: the sister SDKs need the same secret handling and the same field names.

## P17. Section 3.9 store: gaps filled by the PHP SDK

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.9 (was: proposed (T4.5); implemented in the PHP SDK (`src/Store/`)).

- Before: 3.9 lists `store.get()` and `store.edit(input)` and nothing about the input fields, the file upload, or how the
  `AchievementParamUnion` of the result is exposed.
- After: add the rules below to 3.9.
  1. `store.edit(settings)` takes `StoreSettings` with every field optional (a field left out is not sent): avatar,
     banner, customAvatar, storeName, isAchievementsPublic, isFavoritesPublic, isItemsOnSalePublic, isStoreNamePublic,
     isTradeHistoryPublic. Avatar and banner are enums with an unknown fallback on the read side. No client-side
     validation (the API documents no limits).
  2. `customAvatar` is the base64 encoded image, sent as `encodedCustomAvatar`. The multipart `customAvatar` upload is
     not supported by any SDK that sends JSON only (DEVIATIONS 1.1).
  3. `store.get()` is a query and follows the retry table; `store.edit()` is sent once and never retried, failures
     propagate as they are (no money moves).
  4. An achievement parameter is `{name, value}` with `name` an enum (`AchievementCondition`, unknown fallback) and `value` a boolean (`AchievementBooleanParam`) or a string
     (`AchievementStringParam`). The two members share the response name `value` with different types, so the selection
     aliases them (`booleanValue`, `stringValue`; `graphql/overrides/`). A union member the SDK does not know, and a
     null entry, are skipped; a null achievements list is an empty list.
- Why: the sister SDKs need the same input surface and the same shape of achievement parameters.

## P18. Section 3.10 exports (part 1): gaps filled by the PHP SDK

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.10 (was: proposed (T5.1); implemented in the PHP SDK (`src/Export/`)).

- Before: 3.10 lists the export methods, the window values, conditional GET, `maxAge` and the normalisation rules, and
  nothing about timeouts, size limits, redirects, the cache contents, the errors of an export GET or the affiliate link.
- After: add the rules below to 3.10.
  1. Export options: base URL (https origin only), connect timeout 10 s, idle timeout 60 s (the longest silence on the
     connection; there is no total timeout), `maxBodyBytes` 64 MiB. `maxBodyBytes` bounds the DECOMPRESSED size of the
     calls that read a whole file (`meta`, `prices`, `streamPrices`); over it the call fails with the transport error.
     `download` is not bounded (it writes to the destination).
  2. An export GET follows at most 3 redirects, only to an https URL on the same host and port as the base URL; any other
     redirect is a transport error. It sends `Accept-Encoding: gzip`. It is retried on a network error, 429 and every 5xx (500-599; a query keeps its table
     of 429/500/502/503/504), at most the configured attempts; 429 and 503 with `Retry-After` are not retried (SDK_SPEC 1.4).
     Only a 200 is the file (a 304 to a conditional call is `NotModified`): any other 2xx (206, 202, 204) is an `HttpError`.
     A client's own exception, an SDK exception type included, is a `TransportError` without a cause (never an answer of
     the API, never a reason to refresh and replay).
  3. The conditional cache holds the `ETag` and `Last-Modified` of a URL, never the body. A conditional call
     (`prices(conditional: true)`) without a cache is an argument error; with one, a 304 returns `NotModified(url)`.
     Validators are stored after a conditional call parsed its 200 response, so a body that failed to parse never makes
     the next call answer `NotModified`.
  4. Errors: `ExportError` is an abstract parent with `GeneralExportError` (the destination could not be written) and
     `StaleExportError` (`dataAsOf`, `maxAgeSeconds`). Statuses other than 200 and network failures of an export GET are the
     `HttpError` hierarchy and `TransportError`, as for GraphQL; a body that is not the expected JSON is `DecodeError`
     with a path like `prices[3].price`.
  5. `app` is the Steam app id as an integer (730), validated 1..2^31-1. `PriceRow`: `marketHashName`, `price` (USD
     `Money`), `marketProductLink`, `marketProductCount`, `cheapestAssetId` (string), `cheapestFloat` (decimal or null),
     `paintIndex`, `paintSeed` (strings), `inspectLink` (an empty string becomes null), `extra` (every unknown key, raw).
     A float token in an id field is a `DecodeError` (the precision is already lost).
  6. `affiliateLink` accepts only https links on `white.market` or a subdomain (no port, no credentials) and sets or
     replaces the `ref` query parameter (percent-encoded, last), keeping the fragment.
  7. Decoding is bounded per step, not only per body: the compressed body is fed to the inflater 1024 bytes at a time
     and deflate expands a byte by at most 1032, so one step yields at most 1024 * 1032 = 1,056,768 decoded bytes
     (about 1 MiB; `BodyReader::MAX_STEP_BYTES`). A reader that checks its size limit before it keeps a chunk therefore
     holds at most the limit, never the limit plus a step. For exports the body must be complete: an identity body with
     `Content-Length` has exactly that many bytes (one value, or several identical values; absent: no check; any other
     value such as `+1000` or `1000, 999` is a transport error), a gzip body reaches its end marker, and data after the end of the gzip
     stream (a second member, trailing bytes) is a transport error (GraphQL responses keep ignoring it, DEVIATIONS).
     `download` writes through a private temporary file of its own next to the destination, renamed over it on success.
- Deferred: `streamProducts` and the products CSV (T5.2); they need a streaming JSON parser (ADR; `halaxa/json-machine`
  is the candidate named in CLAUDE.md, not added yet).
- Why: the sister SDKs need the same limits, the same error mapping and the same cache semantics.

## P19. Section 3.10 exports (part 2): the products row and CSV

Status: approved by the owner 2026-11-01, merged into SDK_SPEC section 3.10 rules 8-12 (was: proposed (T5.2); implemented in the PHP SDK (`src/Export/ProductRow.php`, `src/Export/Rows/`, `ExportClient::streamProducts`)).

- Before: 3.10 names `streamProducts(app, window?, format=json)` and "CSV supported with the same row models", and
  defines only `PriceRow`. The products CSV header is not documented (WHITEMARKET_API.md 11, open question).
- After: add to 3.10 rule 5 and a new rule 8.
  1. `ProductRow`: `productId`, `marketHashName` (`name_hash`), `price` (`Money` in the row's `currency`; an unknown
     currency is the unknown enum variant), `category` (the item category enum or unknown), `type`, `subtype` (raw
     strings or null), `classId`, `instanceId`, `assetId` (digit strings), `float` (decimal or null), `paintIndex`,
     `paintSeed` (strings or null; numbers in the file), `phase` (raw string or null), `deliveryType` (`auto|semi|manual`
     -> the delivery type enum, compared upper-cased; unknown variant otherwise; null when absent), `deliveryPromise`
     (`10min|1hour|12hours|24hours` -> `MINUTES10|HOUR1|HOURS12|HOURS24`; unknown variant otherwise; null when absent),
     `inspectLink` (null or an empty string becomes null), `extra` (every unknown key, raw).
  2. `streamProducts` sends its requests (meta for `maxAge`, then the file) when called, so a status other than 200 fails
     at the call; the body is parsed as it arrives, one row in memory, with no `maxBodyBytes` bound. A row that fails to
     decode or a body that breaks off fails at that row of the iteration, after the rows before it. Stopping the
     iteration closes the connection.
  3. JSON: the body must be a top-level array (an object, a scalar or an empty body is `DecodeError` at `products`); a
     syntax error or a cut-short array is `DecodeError` at `products[<index of the row being read>]`, without server bytes
     in the message.
  4. CSV (RFC 4180, `,`, `"` with `""`, CRLF or LF, a UTF-8 BOM ignored): the first line is the header and names the
     columns with the JSON keys; columns are matched by name, not position, so order does not matter; an empty value is
     null; every value is a string (ids, paint index and seed as digit strings); an empty line is skipped. No header, an
     empty or repeated column name, a record whose field count differs from the header, a body that ends inside a quoted
     field or a record over 1 MiB is a `DecodeError`.
  5. A language SDK may offer faster optional JSON parsers behind an option (PHP: ADR 0006, `JsonStreamDecoder`); every
     one must give the rows above and must never round an id: one that cannot keep an integer beyond the 64-bit range
     fails that row with `DecodeError`.
- Why: the sister SDKs need the same row model and the same failure points. The CSV header naming the JSON keys is an
  assumption until the backend answers (WHITEMARKET_API.md 11); matching by name makes a different header fail loudly
  (`missing` field) instead of mapping columns wrongly.

## P20. Section 3.11 live: behaviour fixed by the live probe and the PHP design

Status: approved by the owner and merged into SDK_SPEC 3.11 (2026-11-01). Source: `docs/plans/2026-11-01-live-design.md` (owner answers O1-O4),
WHITEMARKET_API.md 9 "Live observations".

1. Personal channel: the server subscribes it on connect (with `broadcast`); SDKs do not send a subscribe and do not
   call `profile_id`; personal channels are recognised by the leading `#`; a `105 already subscribed` reply to any
   subscribe counts as success.
2. `ProductAdded` / `ProductEdited`: add `stickers`, `keychains` (raw lists); `price` is money in USD (the message has
   no currency; owner decision, backend question WHITEMARKET_API 11.7). Deal events: add `steamId` (`deal_steam_id`).
3. Pull API `poll(timeout) -> event | null` next to the iterator (synchronous languages need it for
   `deals.waitFor(live)`); `waitFor` takes an optional live session and polls GraphQL early on a matching deal event.
4. Token refresh: reconnect with a fresh token when the connect reply `ttl` says under 10 minutes are left (both
   transports); 109 / 3500 trigger one refresh + reconnect, a second in a row is terminal.
5. A malformed message of a known type becomes `UnknownEvent` + `onError`; the stream continues.
6. Reconnect: full jitter, 0.5 s base, 20 s cap (centrifuge-js defaults), attempt counter reset after 60 s up;
   unlimited by default. Idle deadline `ping + 10 s`. Codes 3000-3499 / 4000-4499 reconnect, others terminal.
7. `onConnected` carries a `reconnected` flag: messages during a reconnect are lost (no offsets), users resync via
   GraphQL.
8. Default transport WebSocket.

## P21. Section 3.11 live: close codes, TLS only, handler exceptions, reconnect bounds

Status: merged into SDK_SPEC 3.11 (2026-11-01, owner).

1. Codes outside 3000-3499, 3500-3999, 4000-4499, 4500-4999 (WebSocket 1000, 1001, 1006, ...) reconnect with the normal
   backoff, at most 5 consecutive times; the 6th ends the session with `LiveConnectionError` (code + reason). The counter
   resets only after a connection stayed up 60 s. 3500-3999 and 4500-4999 stay terminal; 3000-3499 and 4000-4499 stay
   unlimited (subject to `maxReconnectAttempts`).
2. Live is TLS only (wss / https); the base URL is https-only.
3. Close/disconnect reasons are server text, printable ASCII, cut to 128 bytes; callers branch on the code.
4. Handler exceptions propagate out of `poll()` / `run()` / the iterator / `waitFor()`.
5. Reconnect bounds already implemented in PHP and now in the spec: `Retry-After` only lengthens the backoff; server-
   triggered immediate reconnects only after 60 s up; token refresh at most once per streak for ttl / 3005 plus once for
   an auth rejection; ping interval clamped to 300 s.

## P22. Section 1.2 operation generator: abstract fields at the depth limit

Status: decided B (owner, 2026-11-02), implemented in PHP and merged into SDK_SPEC 1.2 (session 23): the shared
`tools/codegen/generate-operations.mjs` selects `__typename id` for an interface field at the depth limit, which changes
the generated documents and CHECKSUMS; every sister SDK copies the same `.mjs` and regenerates. The PHP language codegen
keeps rule A as a fallback for hand-written overrides that select an interface without `__typename` (owner decision,
2026-11-02: keep it).

- Fact: at the depth limit the operation generator selects an interface field as `{ id }` (rule "at the limit, select
  only `id`"), without `__typename` and without inline fragments (e.g. `market_list` ...
  `product { item { id } }`, `MarketProduct.item: InventoryItemInterface`). A codegen that always decodes an interface
  by `__typename` fails on every such response.
- Before: "Interfaces and unions: `__typename` plus `... on <Type> { ... }` for every possible type" (silent about the
  depth limit).
- After (one of two, owner chooses):
  A. Codegen rule (what PHP does now): "An interface selection without `__typename` and without a concrete-type
     fragment is decoded as one object holding the interface's own selected fields." Operations unchanged.
  B. Generator rule: "At the depth limit an interface field selects `__typename id`." Changes the operation documents
     of all seven SDKs (CHECKSUMS) and restores the discriminator.

## P23. Section 3.4 deals: `waitFor` for seller deals

Status: implemented in PHP (owner bug report, 2026-11-02); merged into SDK_SPEC 3.4 rule 12 (owner, 2026-11-02).

- Fact [observed 2026-11-02]: `deal_buyer_statuses([id])` returns no row for a deal where the caller is the seller
  (a SEMI sale), while `deal_item(id)` returns it. A `waitFor` that only polls `deal_buyer_statuses` never sees a
  seller deal change and runs until its timeout.
- Before (3.4 table): "`deals.waitFor(...)` polls `deal_buyer_statuses`".
- After: "polls `deal_buyer_statuses`; when the reference is not in the answer, reads the deal with `deals.get`
  (`deal_item`, then `deal_history_item`) and keeps using `get` for the rest of the call. A not-found answer of both
  is 'not there yet', like an absent status."

## P24. Section 3.3 buying: a price cap on every buy form

Status: implemented in PHP (owner decision, 2026-11-02); merged into SDK_SPEC 3.3 (method table, rule 7; owner,
2026-11-02). The server honours the cap with `id`
[observed 2026-11-02: refused with `market.product.not_found_in_specified_price`, no deal]; `appIdAssetIdInput` not tried.

- Before: only `buyCheapest(app, nameHash, maxPrice?, ...)` takes a maximum price.
- After: `buyProduct(productId, maxPrice?, options?)` and `buyByAssetId(app, assetId, maxPrice?, options?)` send
  `maxPriceInput` too; null sends nothing. Same currency validation as `buyCheapest`.
- Why: a listing's price can change between reading it and buying it; without a cap the purchase takes whatever price
  holds at that moment.
