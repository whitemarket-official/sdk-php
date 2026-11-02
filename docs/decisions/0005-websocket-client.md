# 0005. Live updates: WebSocket client library

- Status: accepted (owner decision 2026-11-01)
- Date: 2026-11-01

## Context

SDK_SPEC 3.11: live updates over `transport = WS | SSE`, a blocking iterator (PHP `Generator`), unlimited reconnect
with capped exponential backoff and jitter (SDK_SPEC 1.4), token refresh before reconnect, resubscribe. The server is
Centrifugo with the JSON protocol (WHITEMARKET_API.md 9): `wss://api.white.market/ws_endpoint`, no custom handshake
headers, the JWT goes in the first `connect` command; one frame may hold several `\n`-separated messages.

Centrifugo v6 keeps the connection alive with application-level pings: the server sends `{}` and the client must
answer `{}` when the connect reply has `pong: true`, or it closes the connection (WHITEMARKET_API.md 9, Centrifugo
protocol facts); WebSocket frame ping/pong is a debug-only mode. So no library's frame
ping/pong handling helps: the SDK answers `{}` in its own read loop. What matters in a library is a blocking read with
a timeout, TLS verification, frame and message size limits, and no event loop. CLAUDE.md rule 10: the dependency
needs an ADR; SDK_SPEC 3.11 and rule 18 keep WS in scope (it cannot be dropped for SSE only without a spec change).

## Options

1. `phrity/websocket` 4.1.0 (2026-10-25, ISC, `php: ^8.2`). Synchronous, pure PHP, no event loop; `receive()`
   blocks; configurable timeout, `maxReceivedMessageSize` / `maxReceivedFrameSize` (no limit by default: we must
   set them), stream context for TLS. Actively released. Cost: `nyholm/psr7` (a second PSR-7 implementation next to
   `guzzlehttp/psr7`), `phrity/http`, `phrity/net-uri`, `phrity/net-stream`, `phrity/util-interpolator`,
   `phrity/util-transformer`: seven new packages. To check in its source before implementing: how a read timeout
   surfaces (it must be told apart from a close), whether `FollowRedirect` is off by default, behaviour over
   the size limit.
2. Own RFC 6455 client over `stream_socket_client` (about 300 lines: handshake key check, masking, fragmentation,
   close, frame ping/pong, length parsing, size caps). No dependency; full control of TLS context, proxy, redirects.
   Cost: security-sensitive parsing on an authenticated connection that we own, fuzz, and mutation-test.
3. `amphp/websocket-client` v2.0.2 / `ratchet/pawl` v0.4.3: need an event loop (revolt / react) and 6-10 packages;
   clash with the synchronous Generator design. Rejected.
4. `valtzu/guzzle-websocket-middleware` 1.0.0: reuses Guzzle but only its StreamHandler (forces the handler choice,
   breaks "any PSR-18 client"), no documented timeout or size limit, one young release. Rejected.
5. `textalk/websocket` 1.6.3 (2022): the predecessor of option 1, unmaintained. Rejected.

SSE (both options): no new dependency. A GET to `/sse_endpoint?cf_connect=<json>` through the existing PSR-18
client, read as a stream by our own `data:` parser. The JWT sits in the query string, so the URL must be redacted in
logs and exception messages; subscriptions are fixed in `cf_connect.subs`.

## Decision

Option 1 (owner decision 2026-11-01): `phrity/websocket: ^4.1` as a hard `require`, used only inside `src/Live/`;
SSE is implemented on the existing transport. The source checks listed under option 1 are done before the WS
transport is written; a finding that rules the library out reopens this ADR (option 2 is the fallback).

### Source checks of 4.1.0 (done 2026-11-01)

Read in https://github.com/sirn-se/websocket-php/tree/4.1.0/src and its dependencies; none rules the library out.

- Read timeout: `ConnectionTimeoutException`, distinct from `ConnectionClosedException` and
  `ConnectionFailureException` (`Connection.php:368-421`); phrity does not close the socket on a timeout, so
  `receive()` may be called again. The timeout (default 60 s) is applied once at connect (`Connection.php:102`,
  `Client.php:456`) and also bounds the handshake: to change it, reconnect. A timeout in the middle of a frame is
  not checked, so the SDK treats any timeout as a dead connection when its own idle deadline has passed and
  reconnects rather than reading on.
- Upstream defect: `Frame/FrameHandler.php` ~:200 `if (empty($got))` treats a read that returns exactly `"0"` as an empty read
  and fails the connection. `DeadlineSocketStream::read()` extends a lone `"0"` when more than one byte was asked for; the
  residual case (exactly one byte asked) is in `docs/DEVIATIONS.md`.
- Redirects: no middleware is attached by default (`Client.php:76`); the handshake requires 101 and throws
  `HandshakeException` otherwise. `FollowRedirect` is opt-in and the SDK never adds it.
- Size limits: `maxReceivedFrameSize` / `maxReceivedMessageSize` default to null (unlimited, `Configuration.php:73-74`);
  over a limit a `RuntimeException` surfaces as `ConnectionFailureException` (`Frame/FrameHandler.php:89-92`,
  `Message/MessageHandler.php:72-78`), no 1009 close. The SDK sets both.
- Control frames: without middleware, ping, pong and close frames come back from `receive()` as `Ping` / `Pong` /
  `Close` objects (`Message/OpcodeRegistry.php:24-28`), not auto-answered. The SDK adds `PingResponder` and
  `CloseHandler` or answers them itself; a `Binary` message is a protocol error (Centrifugo JSON uses text frames).
- TLS: `wss` -> `ssl://` through `stream_socket_client` with the configured `Phrity\Net\Context`
  (`Client.php:445-454`; net-stream 2.4.0 `SocketClient.php:93-110` sets no TLS options itself). The SDK sets
  `verify_peer`, `verify_peer_name` and `peer_name` explicitly instead of relying on PHP defaults.
- Headers: `Client::addHeader()` (`Client.php:235`); no HTTP proxy support.
- Errors: net-stream wraps socket calls in `phrity/util-errorhandler` (`set_error_handler` / `restore_error_handler`,
  scoped). No `@` suppression in `Client.php`.
- `require` of 4.1.0: `nyholm/psr7`, `phrity/http`, `phrity/net-uri`, `phrity/net-stream ^2.4`,
  `phrity/util-interpolator`, `phrity/util-transformer`, PSR interfaces; net-stream adds `phrity/util-errorhandler`.
  `composer require` (2026-11-01) installed ten packages, not seven: `phrity/websocket` 4.1.0, `phrity/http` 1.1.0,
  `phrity/net-uri` 2.2.1, `phrity/net-stream` 2.4.0, `phrity/util-errorhandler` 1.2.2, `phrity/util-interpolator`
  1.1.2, `phrity/util-transformer` 1.4.1, `phrity/util-accessor` 1.3.1, `phrity/comparison` 1.4.1, `nyholm/psr7`
  1.8.2. Only `phrity/net-stream` is used directly (L3: `Context` for the TLS options, `StreamFactory`/`SocketClient` to keep the socket for `stream_select`), so it is declared in `composer.json` (rule 10); the others are not used outside `phrity/websocket`.

## Consequences

- Option 1: `make deps` and `make audit` cover ten more packages (see the source checks); the WS layer is internal to `src/Live/`, so a
  later switch to option 2 is not a breaking change.
- Option 2: no new packages; a dedicated fuzz/mutation test suite for the frame parser before release.
- Either way: the SDK answers Centrifugo's `{}` ping itself, enforces its own idle deadline, caps received message
  size (about 1 MiB), keeps TLS verification on, and never follows a cross-host redirect on the handshake.

## Sources

- https://repo.packagist.org/p2/phrity/websocket.json (4.1.0 `require`, license, date; 3.8.1 `php: ^8.1`; checked
  2026-11-01)
- https://centrifugal.dev/docs/transports/websocket (v6: app-level `{}` ping, `cf_ws_frame_ping_pong` debug only;
  checked 2026-11-01)
- https://github.com/sirn-se/websocket-php `docs/Client.md`, `docs/Configuration.md` (read by a research subagent,
  not re-read by the author)
- Packagist pages of amphp/websocket-client, ratchet/pawl, valtzu/guzzle-websocket-middleware, textalk/websocket
  (versions and dependency lists, via the research subagent)
