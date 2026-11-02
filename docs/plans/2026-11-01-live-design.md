# Design: live updates (P6, SDK_SPEC 3.11)

Status: draft, 2026-11-01. Inputs: SDK_SPEC 3.11, WHITEMARKET_API.md 9 (incl. "Live observations"), ADR 0005.
No sister SDK has a live module yet, so this design is the reference for all seven (rule 18).

## Goals and non-goals

- One blocking, synchronous API (no event loop): a pull method plus a `Generator`, plus handlers for the SDK_SPEC
  `on(...)` / `subscribe*(handler)` shape.
- WS (default) and SSE behind one internal connection interface; reconnect forever with capped full-jitter backoff,
  fresh token before a reconnect, resubscribe.
- Events are hints (SDK_SPEC 3.11): no business decision inside the module; `deals.waitFor` confirms through GraphQL.
- Non-goals: history recovery (publications carry no `offset`, observed), publishing, presence, async runtimes.

## Public API

```php
$live = $client->live(new LiveOptions(transport: LiveTransport::WebSocket));
$live->subscribeProducts();                                   // market_products_updates
$live->subscribePersonal();                                   // #<personId>, server-side (see below)
$live->on(DealCompleted::class, function (DealCompleted $e): void { ... });
$live->onConnected(fn (LiveConnected $c) => ...);             // also onConnecting, onDisconnected, onError

foreach ($live->events() as $event) { ... }                    // blocks; reconnects; ends after close()
$event = $live->poll(timeout: 5.0);                            // ?LiveEvent, null on timeout
$live->run(duration: null);                                    // dispatch to handlers only
$live->close();
```

- `subscribeProducts(?\Closure $handler = null)` and `subscribePersonal(?\Closure $handler = null)`: the handler is
  sugar for `on()` limited to that channel. Subscribing after the connection is up sends the subscribe at once (WS) or
  reconnects with the new `cf_connect.subs` (SSE).
- `on(class-string<LiveEvent> $type, \Closure $handler)`: `LiveEvent::class` matches all, `UnknownEvent::class`
  matches unknown ones. Handlers run inside `poll()` / `events()` / `run()`, before the event is returned. A handler
  exception propagates to the caller and leaves the connection open (the caller may continue or `close()`).
- `close()` is idempotent; `events()` ends at the next boundary; callable from a handler.
- `client->live()` returns a new `Live` per call (one connection each). A standalone `Live::create(...)` like
  `ExportClient::create` is not needed: live needs auth.

## Events (`WhiteMarket\Sdk\Live\Event\*`, `final readonly`)

| Class | From `type` | Fields |
|---|---|---|
| `ProductAdded` | `market_product_added` | `id`, `appId`, `assetId`, `classId`, `instanceId` (digit strings), `price` (`Money`, USD, O1), `marketHashName`, `inspectLink` (?string), `deliveryType` (`DeliveryType\|UnknownEnumValue\|null`), `deliveryPromise` (`DeliveryPromise\|UnknownEnumValue\|null`), `float` (`?Decimal`), `paintIndex`, `paintSeed` (?string), `stickers`, `keychains` (raw `list<array>`), `extra` (unmapped keys) |
| `ProductEdited` | `market_product_edited` | `id` required, every other field of `ProductAdded` nullable (partial update) |
| `ProductRemoved` | `market_product_removed` | `id`, `appId`, `assetId`, `classId`, `instanceId`, `price`, `marketHashName` |
| `DealInitiated`, `DealOffered`, `DealNeedConfirmation`, `DealExchanged`, `DealCompleted`, `DealExpired`, `DealCancelled`, `DealHold` | `market_deal_*` | common base `DealEvent`: `dealId`, `action` (`DealAction\|UnknownEnumValue`), `productName` (?string), `steamId` (?string, O4), `extra` |
| `UnknownEvent` | anything else (incl. `broadcast`, wallet/order/inventory types) | `type` (?string), `channel`, `rawContent` (decoded `array` or the raw string when not JSON) |

- Every event has `channel` (string). Personal channels are recognised by the leading `#`, so no `profile_id` call.
- `price` is `Money` in USD (O1): live messages carry no currency field.
- `deal_steam_id` [server] is the typed `steamId` (O4); the SDK never logs it.
- A message that is valid JSON but has the wrong shape for its known type (missing `id`, non-string scalar) becomes
  an `UnknownEvent` plus an `onError(DecodeException)` call, never a thrown exception or a dropped connection
  (rule 5; the stream must survive one bad message among 250/s).

## Internals (`src/Live/`, all `@internal` except the public types above)

- `LiveSession`: the state machine. `connect -> handshake -> read loop -> reconnect`; owns subscriptions, the idle
  deadline, backoff, token handling, handler dispatch.
- `Connection` interface: `open(Secret $token, list<string> $channels, float $deadline): ConnectInfo`,
  `read(float $deadline): ?string` (one raw Centrifugo message or null on deadline), `subscribe(string $channel)`,
  `pong()`, `close()`. Implementations:
  - `WebSocketConnection` over `phrity/websocket`: `Configuration(timeout, maxReceivedFrameSize = maxReceivedMessageSize
    = 1 MiB)`, TLS context `verify_peer`, `verify_peer_name`, `peer_name` set explicitly, no middleware (no
    `FollowRedirect`; control frames handled by us: ping -> pong frame, close -> code), no logger passed to phrity
    (its debug log could print the connect command with the JWT). Splits frames on `\n`.
  - `SseConnection` over the PSR-18 client: GET `<base>/sse_endpoint?cf_connect=<json>`, `Accept: text/event-stream`,
    reads with `BodyReader`, own line parser (line cap 1 MiB, `data:` only, multi-line `data` joined per the SSE spec,
    comments and other fields ignored). The default Guzzle client for live: `GuzzleClientFactory::createForLive()`
    (connect timeout, `read_timeout` = idle deadline, no total timeout, `stream`, no redirects, verify on).
- `MessageDecoder`: one raw message -> `Ping | Reply(id, result|error) | Publication(channel, data) | Disconnect(code,
  reason) | Unsubscribe(channel, code) | ConnectReply(...)`. `json_decode` with `JSON_BIGINT_AS_STRING`, depth 64,
  `JSON_THROW_ON_ERROR`; `pub.data.message` as string -> second decode, as object -> as is.
- `EventMapper`: `Publication` -> `LiveEvent` (the one place per model, SDK_SPEC 4). Not merged with `ModelMapper` /
  `ExportMapper`: input is Centrifugo publications with all-string scalars, output the event types; it reuses
  `Decimal::tryOf`, the enum fallback helper and `UnknownEnumValue`.

### Session rules

1. Token: `TokenProvider::accessToken()` before every connect (it already refreshes 5 min before its own expiry).
   The connect reply `ttl` gives the server's view: `expiresAt = now + ttl`. When `expiresAt - now < 600 s` the session
   calls `refresh()` and reconnects (WS and SSE alike; SSE cannot send `refresh`). One path, one gap per ~24 h
   (open question 2).
2. WS handshake: `connect {token, name: "whitemarket-sdk-php"}` (D5); channels already in the reply `subs`
   are not subscribed again; others get `subscribe`; a `105` reply counts as subscribed. SSE: channels go in
   `cf_connect.subs`.
3. Idle deadline: `ping + 10 s` (`ping` from the connect reply, default 25), reset by any message. Expired ->
   reconnect. WS `{}` -> answer `{}` when `pong: true`.
4. Reconnect: full jitter `random * min(20 s, 0.5 s * 2^(attempt-1))` (centrifuge-js constants), attempt reset after
   a connection stayed up 60 s. Unlimited by default; `LiveOptions::maxReconnectAttempts` (null) caps it.
5. Terminal vs retry (WHITEMARKET_API 9 codes): close/disconnect codes 3000-3499 and 4000-4499 and network errors
   reconnect; 3500-3999 and 4500-4999 end with `LiveConnectionException` (code + Centrifugo reason, both server-controlled:
   reason cut to 128 bytes of printable ASCII). Exception: 3500 (invalid token) and reply error 109 trigger one
   `refresh()` + immediate reconnect; a second one in a row is terminal (`AuthenticationException`).
   Unsubscribe push `>= 2500` resubscribes, `< 2500` drops the channel and calls `onError`.
6. HTTP on SSE: 401 -> refresh once, as rule 5; 429/503 with `Retry-After` -> wait that long (capped at 5 min) then
   reconnect; other 4xx terminal; 5xx reconnect.
7. Secrets: the SSE URL and the WS connect command carry the JWT: never logged, never in an exception message, the
   client exception is never chained (TransportException rule). Logs: connect/disconnect at `info` with code and
   attempt only.
8. Limits: 1 MiB per message (WS frame/message, SSE line), JSON depth 64; one message decoded at a time; memory
   independent of the number of messages (100k-message test, T6.3).

## deals.waitFor(live)

`DealsApi::waitFor(..., ?Live $live = null)`: with a live session it calls `$live->poll(min(pollInterval, left))`
instead of sleeping and polls `statuses()` early when a `DealEvent` for the same deal id arrives (other events are
still dispatched to handlers). Without `$live`, unchanged.

## Tests (T6.3)

- Unit: `MessageDecoder` and `EventMapper` over synthetic fixtures (shapes from the live probe, ids synthetic);
  SSE line parser (chunk boundaries, CRLF, multi-line data, cap); `LiveSession` over a scripted fake `Connection`
  with `ManualClock` + `FixedRandomSource` (backoff values, idle deadline, ttl refresh, 105, terminal codes,
  unsubscribe codes, handler errors, close from a handler).
- Socket level: `WebSocketConnection` against a local `ws://localhost` fixture server (phrity's own `Server`, run as a
  child process like `tests/fixtures/servers/loopback.php`); the https-only origin rule stays for users, the test
  builds the connection directly. `SseConnection` over `FakeHttpClient` with `ChunkedStream`.
- Memory: 100k messages through the session, peak under a fixed budget.
- Live (opt-in, owner): `examples/live_products.php`, `examples/live_personal.php` (now `ws_products_feed.php`, `track_deal_live.php`).

## SDK_SPEC 3.11 change proposal (P20, to apply after owner approval)

1. Personal channel: server-side subscription; the SDK does not subscribe and does not call `profile_id`; a `105`
   reply to a subscribe counts as success.
2. `ProductAdded` / `ProductEdited`: `stickers`, `keychains` raw lists; `price` as money in USD (no currency in the message).
3. Pull API `poll(timeout)` next to the iterator (needed by `deals.waitFor(live)` in synchronous languages).
4. Token refresh = reconnect with a fresh token when the server `ttl` is under 10 min (both transports).
5. A malformed message of a known type becomes `UnknownEvent` + `onError`; the stream continues.
6. Reconnect constants: full jitter, 0.5 s base, 20 s cap, reset after 60 s up; idle deadline `ping + 10 s`.

## Decisions (clarify, 2026-11-01)

Closed without the owner (check / accept):

- D1 `connect()` (SDK_SPEC 3.11 lists it): added as an optional eager connect; `poll()` / `events()` / `run()`
  connect lazily. [check: SDK_SPEC.md 3.11]
- D2 `appId` stays a string (`"730"`): `Models\App` is the schema enum (`CSGO`, `DOTA2`, `TF2`,
  `src/Models/App.php:12`), not the Steam app id; no mapping is guessed. [check]
- D3 Delivery promise wire values in live are window suffixes like the export file (`10min`, observed lengths 5 and
  7). The `10min -> DeliveryPromise::Minutes10` table lives privately in `ExportMapper` (`src/Export/ExportMapper.php:40-47`,
  `:294`): the plan moves it to one shared place used by both mappers (rule 11) instead of copying it. Delivery type
  `auto|semi|manual` -> `strtoupper` like the export mapper. [check]
- D4 Blank optional strings (`inspect_url`, `float`, `paint_index`, `paint_seed` "can be blank on add",
  WHITEMARKET_API 9) map to null; a blank required field (`id`, `price`) is a malformed message (UnknownEvent +
  onError). [accept]
- D5 Connect `name`: `whitemarket-sdk-php` (no version constant exists in `src/`; the User-Agent already identifies
  the SDK). [check]
- D6 `close()` from outside a blocking read (signal handler) is out of scope; it is honoured at the next message or
  deadline (at most the idle deadline, about 35 s). Documented in the README. [accept]
- D7 Handler exceptions propagate out of `poll()` / `events()` / `run()`; the connection stays open and the next call
  continues. [accept, matches "the caller decides"]

Assumptions (move to the plan):

- A1 Personal event shapes (`deal_id`, `deal_action_type`, `market_product_name`) are taken from the docs, never
  observed (no deal during the probe). If wrong: events arrive as `UnknownEvent` + `onError`, no crash. accepted
  unchecked; confirmed by the owner's live example run (T6.3).
- A2 The connect reply `ttl` equals the JWT's remaining lifetime (observed 86400 on a fresh token; 86359 on the second
  connection 41 s later). [observed]
- A3 The SSE URL with a JWT and two channels stays under Centrifugo's 2048-char guidance (it worked in the probe).
  [observed]
- A4 Token expiry on the wire is unknown (109 / 3500 / 3005): the session handles all three the same way (refresh +
  reconnect), so the code does not depend on which one the server uses. [accepted unchecked]

Owner answers (2026-11-01):

- O1 Live `price`: `Money` in USD (owner), like the export files. Recorded as an assumption in WHITEMARKET_API.md 11
  for the backend to confirm; if another currency ever appears the field is still a valid `Money`, only the
  currency is wrong.
- O2 Reconnect gap: `LiveConnected::$reconnected` flag on `onConnected`; no synthetic event.
- O3 Default transport: WebSocket.
- O4 `deal_steam_id`: typed `DealEvent::$steamId` (?string), not `#[\SensitiveParameter]`-worthy (not a secret) but
  never logged by the SDK.

Verdict: no open BLOCKER; the plan may be written.
