# WhiteMarket Partner API: facts reference for SDK authors

This file is the single source of API facts for every WhiteMarket SDK (PHP, Go, JavaScript, TypeScript,
Python, C#, Rust). It is identical in all SDK repositories. If you change it, change it everywhere.

Every fact carries its source:

- `[docs]` public partner documentation, https://api.white.market/docs_partner/index.html
- `[schema]` partner GraphQL schema, `schema/partner.graphql` (public anonymous introspection of the live endpoint, 2026-10-30)
- `[observed]` observed on the live service on 2026-10-30 (HTTP response, file shape)
- `[server]` taken from WhiteMarket server code by the backend team, not in the public docs yet.
  Treat as true, but keep the SDK tolerant: unknown values must never crash the client.

A fact without a source is a hypothesis. Do not add one without a source.

## 1. Endpoints

| Purpose | URL | Auth | Source |
|---|---|---|---|
| Partner GraphQL | `https://api.white.market/graphql/partner` | `Authorization: Bearer <accessToken>` (except `auth_token`) | [docs] Glossary |
| SSE live updates | `https://api.white.market/sse_endpoint` | JWT inside `cf_connect` query parameter | [docs] 7-live-updates/sse |
| WebSocket live updates | `wss://api.white.market/ws_endpoint` | JWT in Centrifugo `connect` command | [docs] 7-live-updates/ws |
| Export: lowest prices | `https://export.white.market/v1/prices/{APP}[.{window}].{json\|csv}` | none (public) | [docs] 5-skins-buy/prices |
| Export: all products | `https://export.white.market/v1/products/{APP}[.{window}].{json\|csv}` | none (public) | [docs] 5-skins-buy/products |
| Export freshness sidecar | `<export file URL>.meta.json` | none | [docs] |
| Playground / docs | `https://api.white.market/docs_partner/` | none | [docs] |

GraphQL introspection on the partner endpoint is open without authentication [observed]:
`POST {"query":"{__schema{queryType{name}}}"}` returns `{"data":{"__schema":{"queryType":{"name":"PartnerQuery"}}}}`.
The schema-sync GitHub Action relies on this.

Introspection details [observed 2026-10-30]:

- The depth limit (11) applies to introspection too: the default `graphql-js` introspection query (type depth 9) is
  rejected with `Max query depth should be 11 but got 13.` A type depth of 7 passes.
- `__Type.specifiedByURL` is not supported (`Cannot query field "specifiedByURL" on type "__Type"`).
- The anonymous schema is the public one and the SDK is generated from it. An earlier snapshot showed more values
  (`Currency` crypto values and `WMD`, `MarketDealStatus.QUEUED`, `MarketProductState.AVAILABLE_FOR_TRADE`,
  `INSTANT_EXCHANGE*` reasons, `MarketProductSearchInput.forTrade`); they are absent from the public schema.
  If the server sends such a value at runtime, the SDK maps it to the unknown variant (rule: unknown is not an error).
- The description of `AuthTokens.accessToken` contains an example JWT. The schema fetch script replaces JWT-shaped
  strings in descriptions with `<JWT>`: repositories never contain tokens.

## 2. Request and response format [docs] Glossary

- HTTP `POST`, `Content-Type: application/json`, body `{"query": "...", "operationName": "...", "variables": {...}}`.
  `operationName` and `variables` are optional.
- Response `{"data": {...}, "errors": [...]}`. `errors` absent when there are no errors.
  `operationName` is required only when the document contains several operations [docs].
- **Only one mutation per HTTP request.** More than one mutation in a request is rejected with HTTP 400.
  The SDK must never batch mutations into one document.
- Server limits [server]: query max depth 11, query max complexity 300. Generated operations must stay within both.

### HTTP status codes [docs]

| Code | Meaning | SDK reaction |
|---|---|---|
| 200 | success, or GraphQL error in `errors` | parse `errors`, raise typed GraphQL error if present |
| 400 | bad JSON, bad GraphQL syntax, more than one mutation in a request | `BadRequestError`, never retry |
| 401 | bad or expired JWT (`{"error":{"code":401,"message":"Expired JWT Token"}}`) | refresh token once, replay once, then `AuthenticationError` |
| 429 | too many identical mutations at the same time | `RateLimitError` (with `retryAfter` if present). Queries without `Retry-After` may be retried with backoff; with it the error is thrown at once; mutations are NOT auto-retried |
| 503 | temporary unavailability, `Retry-After` header tells when | `ServiceUnavailableError` with `retryAfter`. Queries without `Retry-After` retried with backoff; with it the error is thrown at once; mutations NOT auto-retried |
| other 5xx | server error | `ServerError`. Queries retried on 500, 502, 504, other 5xx not; mutations NOT auto-retried (see section 6) |

The docs list only 200, 400, 401, 429 and 503, and mention `Retry-After` only for 503. The "other 5xx" row and
`Retry-After` on 429 are defensive SDK behaviour, not documented server behaviour.
The glossary calls the 401 header "Authentication"; the authorization page and every example use `Authorization` [docs].

### GraphQL error shape [docs]

```json
{
  "errors": [{
    "message": "Oops! Item not found.",
    "locations": [{"line": 2, "column": 3}],
    "path": ["market_item"],
    "extensions": {"category": "market.product.not_found"}
  }]
}
```

- The docs example message is `"❗️ Oops! Item not found."` (with emoji). The glossary text says `extension.category`,
  its example uses `extensions.category`; `extensions` is the GraphQL standard and what the SDK reads.
- `message`: human readable, may change, may contain emoji. Never branch on it.
- `extensions.category`: stable mnemonic error code. Branch on this only.
- A response may carry both partial `data` and `errors`. The SDK exposes both.

## 3. Authentication [docs] 3-authorization

1. The partner generates a **Partner Token** in the WhiteMarket profile (website UI). Long-lived secret.
2. Exchange it for an **access token** (JWT):
   ```graphql
   mutation { auth_token { accessToken } }
   ```
   with HTTP header `X-partner-token: <Partner Token>` and no `Authorization` header.
   Return type `AuthTokens { accessToken: String!, isNewPerson: Boolean! }` [schema].
3. Use `Authorization: Bearer <accessToken>` on every other GraphQL request.
4. Access token lifetime: **24 hours** [docs]. After expiry the API answers 401 `Expired JWT Token`.
5. The same JWT authenticates SSE (`cf_connect.token`) and WebSocket (`connect.token`).

Rules for the SDK:

- `auth_token` is rate limited server-side [server]. Calling it per request will get the partner throttled.
  Cache the access token until shortly before expiry. Use the JWT `exp` claim (decode the payload, do not verify
  the signature, the SDK has no key); if `exp` is missing or unparsable, assume 24 h from issue minus a safety margin.
- Refresh is single-flight: concurrent callers wait for one refresh, never start N refreshes.
- Partner Token and access token are secrets: never logged, never in exception messages, redacted in debug output.
- Alternative auth `auth_steam_jwt(value: String!): AuthTokens!` exists [schema]; expose it in the generated layer,
  in the high-level layer only as an optional token provider.

## 4. Partner GraphQL surface [schema]

Root query `PartnerQuery`:

| Field | Args | Returns | Group |
|---|---|---|---|
| `test_ping` | | `String!` | health |
| `profile_id` | | `Uuid!` | profile |
| `person_profile` | | `PersonFull!` | profile |
| `profile_steam_proxy_get` | | `String` | profile |
| `store_my` | | `PersonStore!` | store |
| `wallet_balance` | `currency: Currency!` | `Money!` | wallet |
| `wallet_balances` | | `[Money!]!` | wallet |
| `wallet_deposits` | | `[WalletDeposit!]!` | wallet |
| `wallet_withdrawals` | | `[WalletWithdrawal!]!` | wallet |
| `wallet_transactions` | `search: WalletTransactionSearchInput, forwardPagination` | `WalletTransactionConnection!` | wallet |
| `wallet_transfers` | `forwardPagination` | `WalletTransferConnection!` | wallet |
| `wallet_credit_max` / `wallet_credit_used` / `wallet_credit_available` | | `Money!` | wallet |
| `market_list` | `search: MarketProductSearchInput, forwardPagination` | `MarketProductConnection!` | market |
| `market_my` | `search: MarketProductSearchInput, forwardPagination` | `MarketProductConnection!` | selling |
| `market_item` | `idOrSlug: String!` | `MarketProduct!` | market |
| `market_csgo_stickers_suggestion` | `search: String!, limit: Int = 10, offset: Int = 0` | `CSGOStickerSuggestions!` | market |
| `market_trade_url` | `link: SteamLinkInput!` | `String!` | profile |
| `inventory_my` | `search: PersonInventorySearchInput, forwardPagination` | `InventoryItemConnection!` | inventory |
| `deal_buyer_list` / `deal_seller_list` | `statusesWith: [MarketDealStatus!], statusesWithout: [MarketDealStatus!]` | `[MarketDeal!]!` (in-progress deals) | deals |
| `deal_item` | `id: Uuid, customId: String` | `MarketDeal!` (in-progress deal) | deals |
| `deal_history` | `search: MarkerDealHistorySearchInput, forwardPagination` | `MarketDealHistoryConnection!` | deals |
| `deal_history_item` | `id: Uuid, customId: String` | `MarketDealHistory!` (archived deal) | deals |
| `deal_buyer_statuses` | `ids: [String!]!` (max 300, UUIDs or customIds) | `[MarketDealState!]!` | deals |
| `deal_balance_hold` | `currency: Currency!` | `Money!` | deals |
| `deal_balances_hold` | | `[Money!]!` | deals |
| `order_my` / `order_my_active` / `order_my_inactive` / `order_my_fulfilled` | `forwardPagination` | `MarketOrderConnection!` | orders |
| `order_list` | `search: MarketOrderSearchInput, forwardPagination` | `MarketOrderPublicConnection!` | orders |

Root mutation `PartnerMutation`:

| Field | Args (abridged) | Returns | Group |
|---|---|---|---|
| `auth_token` | (header `X-partner-token`) | `AuthTokens!` | auth |
| `auth_steam_jwt` | `value: String!` | `AuthTokens!` | auth |
| `market_buy` | `id: Uuid` or `appIdNameHashInput {app, nameHash, phase}` or `appIdAssetIdInput {app, assetId}`; `maxPriceInput: MoneyInput`, `link: SteamLinkInput`, `customId: String` (max 100), `deliveryPromise`, `deliveryType` (DEPRECATED) | `MarketDeal!` | buying |
| `deal_request_cancel` | `id: Uuid!` | `MarketDeal!` | deals (buyer) |
| `deal_steam_offer_sent` | `offerId: String!, id: Uuid, customId: String` | `MarketDeal!` | deals (seller, manual) |
| `deal_cancel_sending_offer` | `reason: MarketDealExternalCancelReason!, comment: String!` (max 255, `[a-zA-Z0-9 _-]`), `id`, `customId` | `MarketDealHistory!` | deals (seller, manual) |
| `market_new` | `inventoryId: Uuid` or `assetId: String`; `price: MoneyInput!`, `deliveryType` (default SEMI), `deliveryPromise`, `lifetime` (sec, default 31536000, min 60, max 365 days) | `MarketProduct!` | selling |
| `market_new_multiple` | `items: [MarketProductNewInput!]!`, `deliveryType`, `deliveryPromise`, `lifetime` | `[MarketProductResult!]!` | selling |
| `market_edit` | `marketProductId: String!, price: MoneyInput!` | `MarketProduct!` | selling |
| `market_edit_multiple` | `items: [MarketProductEditInput!]!` | `[MarketProductResult!]!` | selling |
| `market_remove` | `marketProductId: String!` | `MarketProductHistory!` | selling |
| `market_remove_multiple` | `items: [Uuid!]!` | `[MarketProductResult!]!` | selling |
| `market_lifetime_all` | `value: Int!` (seconds, min 600) | `Boolean!` | selling |
| `inventory_update` | | `PersonFull!` | inventory |
| `order_new` | `app, nameHash, price, quantity, csgoFloat, csgoPhase, link` | `MarketOrder!` | orders |
| `order_edit` | `id, price, quantity, link` | `MarketOrder!` | orders |
| `order_activate` / `order_deactivate` / `order_delete` | `ids: [Uuid!]!` | `[MarketOrder!]!` | orders |
| `order_deactivate_all` / `order_delete_all` | | `Int!` | orders |
| `order_auto_activate` | | `Boolean!` (toggle) | orders |
| `wallet_credit_get` | `moneyInput: MoneyInput!` | `WalletDebt!` | wallet |
| `wallet_transfer_send` | `to: String!` (ID or Steam ID), `amount: MoneyInput!` | `WalletTransfer!` | wallet |
| `profile_steam_link` | `link: SteamLinkInput!` | `Boolean!` | profile |
| `profile_steam_proxy_set` / `profile_steam_proxy_delete` | `proxy: ProxyInput!, ttl: Int` | `Boolean!` | profile |
| `profile_token_set` / `profile_token_check` / `profile_token_delete` | `payload: String!, ownProxyRequired, ttl` | `Boolean!` / `Int` | profile (Steam token) |
| `profile_referrer` / `profile_referrer_code` | | `Boolean!` | profile |
| `profile_steam_api_key` | DEPRECATED "Do not use it" | | excluded from SDK |
| `email_set` / `email_confirm` / `email_confirmation_resend` | | `PersonFull!` | profile |
| `store_edit` | `storeInput: PersonStoreInput!` | `PersonStore!` | store |

Shared types [schema]:

- `Money { value: String!, currency: Currency! }`, `MoneyInput` same shape. Value is a decimal **string**
  (`"0.020"`, `"114.300"`). Never convert to binary float.
- `Currency`: `USD` only in the public schema (2026-10-30). The market operates in `USD` [docs].
  Other values may appear later; they map to the unknown variant.
- `SteamApp`: `CSGO DOTA2 TF2 UNKNOWN`. CS2 is `CSGO` in GraphQL and `730` in exports and live messages.
- `DeliveryType`: `SEMI` (default; WhiteMarket sends the trade offer, seller confirms in Steam Mobile),
  `MANUAL` (seller sends the offer), `AUTO` (reserved, currently returns an error) [docs] 6-skins-sell.
- `DeliveryPromise`: `MINUTES10 HOUR1 HOURS12 HOURS24`. Export and live messages spell them `10min 1hour 12hours 24hours`.
- `ForwardPaginationInput { first: Int, after: String }`; connections are
  `{ edges: [{ cursor, node }], pageInfo { hasNextPage hasPreviousPage startCursor endCursor }, totalCount }`.
- Scalars: `Uuid` (string), `DateTime` (ISO-8601 string with offset), `FileUpload` (not used by partner flows).
- `MarketDealState { id customId steamId tradeId status cancelReason price settlementTimeAt isTradeProtected reversePenaltyValue reverseRefundValue }`.
- Orders [schema, sync of 2026-11-02]: `MarketOrder` is implemented by `CSGOMarketOrder` (own orders: `id app nameHash
  price params phase exterior rarity short skin quantity quantitySuccess quantityProgress quantityFailed status comment
  link createdAt expiredAt icon iconLarge`), `MarketOrderPublic` by `CSGOMarketOrderPublic` (`order_list`: `id app
  nameHash price params quantity isMy inventory category subcategory exterior rarity short skin isSouvenir isStatTrak
  icon iconLarge`). Before that sync both interfaces had no possible type, so `order_*` returned no order data.
  `CSGOMarketOrder.link` is a trade URL with the Steam trade token (handle like `Profile.steamTradeUrl`).
- `MarketProductResult { product: MarketProductUnion, isCreated: Boolean!, errorCategory: String, errorMessage: String, index: Int! }`:
  per-item result of `*_multiple` mutations. A batch call can partially fail; the SDK surfaces per-item results, it does not throw for item failures.

### MarketDealStatus [schema] + classification [server]

| Group | Statuses | Meaning for the SDK |
|---|---|---|
| in progress | `HOLD INITIATED SELLER_SENDING_STEAM_OFFER WAITING_STEAM_QUEUE BUYER_CANCEL_REQUESTED OFFERED BUYER_OFFERED SELLER_OFFERED NEED_CONFIRMATION` | keep waiting |
| exchanged, protected | `EXCHANGED` | items moved in Steam. If `isTradeProtected = true`, Steam Trade Protection applies: wait until `settlementTimeAt` for `COMPLETED`. The deal can still be rolled back during protection |
| success, final | `COMPLETED` | funds settled |
| failed, final | `CANCELLED BUYER_CANCELLED SELLER_CANCELLED EXPIRED BUYER_EXPIRED SELLER_EXPIRED EXPIRING` | no trade. `EXPIRING` is a transitional state that always ends in an expired status |
| reversed, final | `STEAM_ROLLBACK BUYER_STEAM_ROLLBACK SELLER_STEAM_ROLLBACK` | trade was reverted by Steam after exchange; see `reversePenaltyValue`, `reverseRefundValue` |

Unknown status values (server adds a new one) must map to an `UNKNOWN`/unrecognized variant, not crash.
`QUEUED` (waiting to send the trade offer) was in an earlier schema snapshot and is absent from the public schema;
treat it as in progress if it arrives.

Where a deal lives: `deal_item` returns deals that are still in progress; after finishing, a deal moves to history,
read it with `deal_history_item` [docs] 5-skins-buy/buying-skin. `deal_buyer_statuses` accepts both and is the cheap
batch status endpoint (max 300 ids per call).

## 5. Buying flow [docs] 5-skins-buy/buying-skin

If the partner buys to their own Steam account, they must have the WhiteMarket Chrome extension or mobile app installed.

Buy a concrete product:

1. Find the product id via export file (`product_id`) or `market_list`.
2. `market_buy(id: ..., customId: ...)`.
3. Poll `deal_buyer_statuses` (or listen to live deal events) until `OFFERED`.
4. Confirm the trade offer in Steam. With `link` (third-party trade URL) the receiver confirms it.
5. Wait `EXCHANGED` or `COMPLETED`. `EXCHANGED` + `isTradeProtected = true` means wait until `settlementTimeAt` for `COMPLETED`.

Buy the cheapest by name hash: same, but `market_buy(appIdNameHashInput: {app, nameHash, phase}, maxPriceInput: ..., deliveryPromise: ..., customId: ...)`.
(the docs mention only the name hash and an optional max price; `phase` and `deliveryPromise` come from [schema]).

Full info: `deal_item` while in progress, `deal_history_item` after completion.

## 6. Idempotency of purchases (critical) [docs]

> We strongly recommend passing your own `customId` (the deal identifier in your system) to `market_buy`.
> If the server responds with a 5xx error, do not retry the purchase: the deal may already have been created.
> Instead, wait 10-20 seconds and re-check the result via `deal_item` or `deal_history_item` using your `customId`.

SDK contract derived from it:

- High-level `buy*` always sends a `customId`. If the caller gives none, the SDK generates a UUIDv4 and returns it.
- On 5xx, timeout, connection reset after the request was sent, or any ambiguous outcome: **no retry of the mutation**.
  The SDK waits (default 15 s, configurable 10-20 s), then looks up `deal_item(customId)`, then
  `deal_history_item(customId)`. Found: return the deal. Not found after the configured attempts:
  raise `PurchaseOutcomeUnknownError` carrying the `customId`, so the caller can reconcile later.
- No mutation is retried automatically by the transport, ever. Retry policy applies to queries only.

## 7. Selling flow [docs] 6-skins-sell

Requires the Chrome extension or mobile app. Place items with `market_new` / `market_new_multiple`.

- `SEMI`: place, poll `deal_seller_list`, wait `NEED_CONFIRMATION`, confirm the offer in Steam Mobile, wait `EXCHANGED`
  (or buyer cancel), then trade protection until `settlementTimeAt` and `COMPLETED`.
- `MANUAL`: place, poll `deal_seller_list`, wait `SELLER_SENDING_STEAM_OFFER`, send the item (`assetId`) to
  `receiverTradeUrl`, report the Steam offer id with `deal_steam_offer_sent`; if impossible, `deal_cancel_sending_offer`
  with a reason and comment. Wait `EXCHANGED`, then protection, then `COMPLETED`.
- `lifetime` in seconds, product removed after it ends. Smaller `deliveryPromise` sells better.
- Inappropriate cancel reasons can lead to sanctions [schema description of `deal_cancel_sending_offer.reason`].
- If the seller misses the `deliveryPromise` window, the deal is cancelled [docs].

## 8. Export files [docs] 5-skins-buy/prices, 5-skins-buy/products

URL pattern: `https://export.white.market/v1/{prices|products}/{APP}{suffix}.{json|csv}`,
`APP = 730` (CS2), `suffix` = none (all) or `.10min .1hour .12hours .24hours` (max delivery time promised by seller).
Public, no auth. Served with `Content-Encoding: gzip` when requested, `ETag`, `Last-Modified`, `Cache-Control: no-cache` [observed].
Use conditional requests (`If-None-Match` / `If-Modified-Since`) and always request gzip.

### Prices (one row per cheapest lot)

Fields [docs] + JSON types [observed]:

| Field | JSON type | Notes |
|---|---|---|
| `market_hash_name` | string | |
| `price` | string decimal, USD | `"7.060"` |
| `market_product_link` | string URL | add `&ref={REFERRAL_CODE}` for the affiliate program [docs] 1-referral-program |
| `market_product_count` | number | lots in the group |
| `cheapest_asset_id` | **number** | 64-bit range. Normalize to string. JS: must not pass through `Number` above 2^53 |
| `cheapest_float` | string decimal or null | null for items without float (agents, stickers, cases) and sometimes for the cheapest lot |
| `paint_index` | string | `"0"` when not applicable |
| `paint_seed` | string | |
| `inspect_link` | string | may be empty |

**Record key is `(market_hash_name, paint_index)`, not `market_hash_name`** [docs]. Doppler / Gamma Doppler phases share a
name hash and differ by `paint_index`; keying by name only picks an arbitrary phase and can be off by an order of magnitude.
The SDK's lookup helper keys by the pair.

CSV: header `market_hash_name,price,market_product_link,market_product_count,cheapest_asset_id,cheapest_float,paint_index,paint_seed,inspect_link`, quoted strings, empty for null [observed].

### Products (one row per lot)

Fields [docs] + JSON types [observed]: `product_id` (UUID string), `product_category` (`"STICKERS"`, `"GLOVES"`...),
`product_type`, `product_subtype`, `product_class_id` (number, 64-bit range), `product_instance_id` (number),
`product_asset_id` (number, 64-bit range), `product_float` (string or null), `product_paint_index` (**number** or null; note: string in prices),
`product_phase` (string or null), `name_hash`, `price` (string decimal), `currency` (`"USD"`), `product_paint_seed` (number or null),
`deliveryType` (`"auto"|"semi"|"manual"`), `deliveryPromise` (`"10min"|"1hour"|"12hours"|"24hours"`), `inspectLink` (string or null).
Note the mixed naming (`snake_case` and `camelCase`) and the fields present beyond the docs (`product_paint_seed`, `deliveryType`, `inspectLink`).
The SDK maps them to one idiomatic model and keeps unknown extra fields accessible.

Null category rows [observed 2026-11-01, live `730.json`, about 4.5M rows]: 58 rows have `product_category`,
`product_type`, `product_subtype`, `product_float`, `product_paint_index`, `product_paint_seed`, `product_phase` and
`inspectLink` all `null` (keys present); ids, `name_hash`, `price`, `currency`, `deliveryType`, `deliveryPromise` are
set. The first one is near row 210k. The only unknown `product_category` value seen is `OTHERS` (about 2 % of rows).
The CSV header names the JSON keys [observed 2026-11-01]: `product_id,product_category,product_type,product_subtype,
product_class_id,product_instance_id,product_asset_id,product_float,product_paint_index,product_phase,name_hash,price,
currency,product_paint_seed,deliveryType,deliveryPromise,inspectLink`. The meta `items` count (4511700) differed from
the rows streamed a few minutes later (4507958); not investigated. The file is regenerated every few minutes, faster
than a full download (1.5 to 4.5 minutes for about 4.51M rows), so two downloads rarely see the same generation
[observed 2026-11-01].

Size: about 4.8 million records for `730.json` [docs meta example]. **The SDK must stream-parse products**, never load the whole file into memory.

### Freshness sidecar

`<file>.meta.json`: `{"file":"730.json","data_as_of":"2026-10-30T09:24:02+00:00","generated_at":"2026-10-30T09:25:37+00:00","items":20970}` [observed].
The docs show the same shape for products with `"items": 4791531` [docs].
`data_as_of` is the real age of the data (always earlier than `Last-Modified`); `generated_at` when the file was built;
`items` record count. The feed is a snapshot, typically minutes old, the cheapest lot may already be sold [docs].

## 9. Live updates (Centrifugo) [docs] 7-live-updates

The server is Centrifugo. JSON protocol.

### Channels

| Channel | Content | Subscribe | Source |
|---|---|---|---|
| `market_products_updates` | product added / edited / removed, all market | explicit subscribe | [docs] |
| `#<personId>` | personal events of the authenticated partner: deals, orders, wallet, inventory | user-limited channel; `personId` from `profile_id` query | [server] |

Deal events are documented [docs] without the channel name; they are delivered on the personal channel [server].
The SDK must confirm on a live connection whether the personal channel needs an explicit subscribe or arrives as a
server-side subscription, and support both.

### Envelope

```json
{"channel": "market_products_updates", "pub": {"data": {"message": "<JSON-encoded string>"}}}
```

`message` is a JSON **string** to decode a second time in the SSE example; in one WS example it is an already decoded
object [docs]. The SDK accepts both: string -> decode, object -> use as is. Decoded message: `{"type": "...", "content": {...}}`.

### Message types

Products (`market_products_updates`) [docs]:

- `market_product_added`: `id app_id asset_id class_id instance_id price name_hash inspect_url delivery_promise float paint_index paint_seed`
  (+ `delivery_type` in the envelope example). `inspect_url`, `float`, `paint_index`, `paint_seed` can be blank on add;
  a later `market_product_edited` brings them.
- `market_product_edited`: same fields; may carry only `id` and changed fields (`{"id": "...", "price": "2.10"}`) [docs WS example].
- `market_product_removed`: `id app_id asset_id class_id instance_id price name_hash`.

All values in product messages are strings (`"730"`, `"0.020"`, `"232"`).

The docs show the `market_deal_exchanged` payload under the heading "Completed" [docs]. It is the EXCHANGED
event, not status `COMPLETED`; `market_deal_completed` is a separate type [server].

Deals (personal channel), content `{deal_id, deal_action_type ("buy"|"sell"), market_product_name}` [docs], plus `deal_steam_id` [server]:
`market_deal_initiated`, `market_deal_offered`, `market_deal_need_confirmation`, `market_deal_exchanged`, `market_deal_expired`,
`market_deal_cancelled` [docs], `market_deal_completed`, `market_deal_hold` [server].
Deal events are notifications: after one arrives, fetch the authoritative state with `deal_buyer_statuses` / `deal_item`.

Other personal types exist [server] (`market_order_auto_activate`, `market_order_auto_deactivate`, `wallet_balance_updated`,
`inventory_updated`, `wallet_deposit_*`, `wallet_withdrawal_*`, ...). The SDK models the documented ones and delivers
everything else as a generic `UnknownEvent { type, rawContent }`. Unknown types never throw.

### SSE transport [docs]

```
GET https://api.white.market/sse_endpoint?cf_connect=<url-encoded JSON>
cf_connect = {"token": "<JWT>", "subs": {"market_products_updates": {}}}
```

(`"debug": true` appears in the docs example; do not send it by default.) Every SSE `data:` line is one envelope.
Non-publication frames (connect reply, pings) are skipped. Reconnect on drop with exponential backoff and jitter;
before reconnecting, get a fresh JWT if the current one is close to expiry (24 h lifetime).

### WebSocket transport [docs]

Official clients recommended by the docs: centrifuge-js, centrifuge-go, centrifuge-python, centrifuge-java, centrifuge-dart, centrifuge-swift.
Raw protocol for languages without an official client:

```
-> {"connect":{"token":"JWT_TOKEN","name":"<sdk name>"},"id":1}
-> {"subscribe":{"channel":"market_products_updates"},"id":2}
<- {"push":{"channel":"market_products_updates","pub":{"data":{"message":{...}}}}}
```

One WebSocket frame can contain several JSON messages separated by `\n` [docs]. Split before decoding.
The multi-message example on the ws page is malformed JSON (a stray `"` before the closing braces): do not copy it
into fixtures. Product examples on the sse/ws/messages pages contain Steam64-shaped ids inside `inspect_url`:
replace them with synthetic values in fixtures.
### Centrifugo protocol facts [centrifugo docs, checked 2026-11-01]

Generic Centrifugo behaviour; whether the WhiteMarket deployment matches each point is confirmed on a live connection
(T6.3) and recorded here with `[observed]`.

- Commands carry an incrementing client `id`; a reply echoes it; a message without `id` is an async push
  (`{"push":{"channel":..,"pub"|"join"|"leave"|"unsubscribe"|"disconnect"|...}}`). A failed command replies
  `{"id":N,"error":{"code":..,"message":..,"temporary":bool}}`. Sources: https://centrifugal.dev/docs/transports/client_protocol,
  https://github.com/centrifugal/protocol/blob/master/definitions/client.proto
- Connect reply fields: `client`, `version`, `expires`, `ttl`, `data`, `subs` (server-side subscriptions, channel ->
  subscribe result), `ping` (seconds), `pong` (bool), `session`, `node`. `ping` is seconds: centrifuge-js multiplies it
  by 1000 (https://github.com/centrifugal/centrifuge-js/blob/master/src/centrifuge.ts, `_serverPing = result.ping * 1000`).
- Ping: the server sends an empty message `{}` (default every 25 s). The client answers `{}` **only if** the connect
  reply has `pong: true`; a missing pong closes the connection with 3012. Any received message resets the client's
  idle timer; centrifuge-js treats the connection as dead after `ping + 10 s` (`maxServerPingDelay` 10000 ms).
  Sources: https://centrifugal.dev/docs/transports/client_protocol, https://centrifugal.dev/docs/transports/websocket
- Close / disconnect codes: 3000-3499 and 4000-4499 reconnect, 3500-3999 and 4500-4999 are terminal (3500 invalid
  token, 3501 bad request, 3503 force disconnect, 3507 permission denied, ...). Unsubscribe push codes 2000-2999:
  >= 2500 resubscribe, < 2500 stay unsubscribed. Sources: https://centrifugal.dev/docs/transports/client_api,
  https://centrifugal.dev/docs/server/codes
- Error codes: 100 internal (temporary), 101 unauthorized, 103 permission denied, 105 already subscribed, 107 bad
  request, 109 token expired (refresh the token), 111 too many requests (temporary). Source: https://centrifugal.dev/docs/server/codes
- Token expiry: the JWT `exp` sets the connection expiry; the connect reply then has `expires: true` and `ttl`
  (seconds left). The client sends `{"refresh":{"token":".."}}` before expiry, or reconnects with a new token; the
  server grants a grace period (default 25 s) and then closes. Source: https://centrifugal.dev/docs/server/authentication
- User-limited channels: `#42` is allowed for a connection whose JWT `sub` is `42` (namespace option
  `allow_user_limited_channels`); server-side subscriptions from JWT claims appear in the connect reply `subs`
  (e.g. `"subs":{"#2694":{}}`). Subscribing again to an already subscribed channel replies 105.
  Sources: https://centrifugal.dev/docs/server/channels, https://centrifugal.dev/docs/transports/uni_sse
- Uni SSE: each event is one `data: {json}` line (no `event:`/`id:`); the first event is the connect reply
  `{"connect":{...}}`, then pushes, and `data: {}` as a ping. Source: https://centrifugal.dev/docs/transports/uni_sse
- Reconnect backoff in centrifuge-js: full jitter, min 500 ms, max 20 s (`centrifuge.ts` defaults, `utils.ts` backoff).

### Live observations [observed 2026-11-01, read-only probe, 40 s per transport]

- Server: Centrifugo `6.7.2 OSS` (connect reply `version`).
- Connect reply, WS: `client version expires ttl subs ping pong`, `ping: 25`, `pong: true`, `expires: true`,
  `ttl` about 86400 (the 24 h JWT). SSE: `client version subs expires ttl ping session`, `ping: 25`, no `pong`.
- Server-side subscriptions: both transports get `broadcast` and `#<personId>` in the connect reply `subs` without
  asking. An explicit WS `subscribe` to `#<personId>` replies error `105 already subscribed`: the SDK must not send
  it (or must treat 105 on the personal channel as success). No publication arrived on `broadcast` during the probe;
  its content is unknown.
- `market_products_updates` needs an explicit subscribe on WS (empty subscribe result) and is honoured from
  `cf_connect.subs` on SSE (it appears in the SSE connect reply `subs`).
- Publications: `{"push":{"channel":..,"pub":{"data":{"message":"<JSON string>"}}}}` on WS, the same object
  without `push` on SSE. `message` was a JSON **string** on both transports for every message (about 16k on WS).
  `pub` had only `data` (no `offset`, `info`, `tags`).
- Product content keys: added/edited `id app_id asset_id class_id instance_id price name_hash inspect_url
  delivery_type delivery_promise float paint_index paint_seed stickers keychains`; removed `id app_id class_id
  instance_id price asset_id name_hash`. `stickers` and `keychains` are arrays (empty in the samples) and are not in
  the docs. Every scalar is a string; `instance_id` can be `"0"`.
- Volume: about 250 product messages/s on WS, 2445 frames with more than one `\n`-separated message in 40 s; SSE
  delivered about 10k in 40 s. Server `{}` pings were rare (1-2 per 40 s) while data flowed.
- SSE: every line was `data: {json}` (no other fields); the first event is the connect reply.
- SDK run through the PHP live module [observed 2026-11-01, `wss://` default and SSE, read-only]: WS 60 s ->
  1769 product events (added/edited/removed), SSE 30 s -> 9859, no decode or mapping errors, clean close. Volume
  varies strongly over time; no personal events (no deal activity during the run).

- Personal channel [observed 2026-11-02, SDK run with fresh purchases]: no subscribe command is needed (the server
  subscribes on connect); `DealOffered`, `DealExchanged` and `DealCancelled` events arrived for the purchases
  (open question 2 and the personal-event shapes are confirmed).

Still unconfirmed: how token expiry shows on the wire (109 reply vs a disconnect push vs a close code; needs a run
past `ttl`), how SSE signals a server disconnect, the content of `broadcast`, personal-channel event shapes (no deal
happened during the probe).

### Live observations: GraphQL server bugs [observed by the PHP SDK, 2026-11-01 and 2026-11-02]

- `MarketProductHistory.params` (a deal's product item, type `InventoryItemInterface`, concrete `CSGOInventoryItem`)
  [observed 2026-11-01]: selecting `updatedAt` returns the error "Internal error occurred" with no
  `extensions.category` for every archived row (5 of 5; control: the same document without the field succeeds).
  With `updatedAt` removed, selecting `params.order { id }` makes the server answer HTTP 500 (field-by-field bisect).
- `market_buy` [observed 2026-11-02]: the same `product.params.updatedAt` error (path
  `market_buy.product.params.updatedAt`) comes back and the purchase is rolled back: no deal with the `customId`
  exists afterwards and the listing stays (2 of 2). After dropping both fields (`updatedAt`, `order`) from the
  selection, 4 purchases succeeded.
- `market_trade_url(link:)` [observed 2026-11-01]: with a Steam profile URL the server answers an uncategorized
  "An unknown error occurred."; a trade offer URL (`https://steamcommunity.com/tradeoffer/new/?partner=..&token=..`)
  answers normally.

### Live observations: buying [observed by the PHP SDK, 2026-11-02]

- `market_buy(id:, maxPriceInput:)` with a cap below the listing price (cap 0.01 USD, listing 0.03 USD) is refused
  with `extensions.category` `market.product.not_found_in_specified_price` at path `market_buy`; no deal with the
  `customId` exists afterwards (1 of 1). The cap applies to the `id` form too, not only to `appIdNameHashInput`.
  `appIdAssetIdInput` with a cap was not tried.

## 10. Affiliate links [docs] 1-referral-program

`https://white.market/item?appId=730&nameHash=<url-encoded name>&ref={REFERRAL_CODE}`. The export `market_product_link`
plus `&ref=` gives the affiliate link. Helper in the SDK: `affiliateLink(link, referralCode)`.

## 11. Open questions (confirm with the WhiteMarket backend team, do not guess)

1. Exact `extensions.category` catalogue for partner errors (needed for typed error mapping beyond the generic one).
2. ~~Whether the personal channel `#<personId>` is server-side subscribed on connect~~: answered [observed 2026-11-01],
   server-side (section 9 "Live observations").
3. Whether SSE/WS accept the Centrifugo `recover` / offset-epoch mechanism for gap-free resume.
4. Rate limits per operation that partners may rely on (today only "429 on too many identical mutations" is public).
5. `Retry-After` format (seconds vs HTTP-date): handle both.
6. Whether `deal_item(customId)` on a non-existent deal returns a GraphQL error with a stable category, and which one.
7. Currency of `price` in live product events (no currency field). The SDK assumes USD (owner decision 2026-11-01,
   like the export files).
8. Content of the server-side `broadcast` channel (nothing arrived during the 2026-11-01 probe).
