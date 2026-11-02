# 0003. Access token lifecycle: provider, store, 401 replay

- Status: accepted
- Date: 2026-10-31

## Context

SDK_SPEC 1.4 (401 -> refresh once, replay once, also for mutations), 2 (`partnerToken`, `accessToken`,
`tokenProvider`, `tokenStore`), 3.1 (`auth.accessToken/refresh/ping`); WHITEMARKET_API.md 3: `auth_token` is
exchanged with `X-partner-token`, is rate limited, the JWT lives 24 h, its `exp` claim is read without verification.
The transport (ADR 0002) takes authentication headers per request through the internal `Credentials` interface.

Constraints: no cross-process lock (owner decision 2026-10-31: a rare second `auth_token` from another worker at
expiry is accepted, docs/DEVIATIONS.md); CLAUDE.md rule 10 for the new runtime dependency; secrets stay in `Secret`.

## Options

1. Refresh inside the transport (it knows the 401). Mixes HTTP with token policy; the transport would need the
   provider and could not stay below the generated layer.
2. A hook on `Credentials` that `GraphQLExecutor` calls after an `AuthenticationException`: the executor owns "one
   operation", so it is the one place that can replay the same body once.
3. A decorator `OperationExecutor` around `GraphQLExecutor`. Same effect as 2, but a second executor class whose only
   job is a retry loop, and it would have to rebuild the headers it does not see.

## Decision

Option 2.

- Public: `Auth\TokenProvider` (`accessToken(): Secret`, `refresh(): Secret`), `Auth\TokenStore`
  (`get(): ?StoredToken`, `set(Secret $token, DateTimeImmutable $expiresAt)`, `clear()`; the SDK_SPEC 2 signature), `Auth\StoredToken`,
  `Auth\InMemoryTokenStore` (default), `Auth\Psr16TokenStore`.
- Internal: `PartnerTokenProvider` (sends `auth_token` through its own `GraphQLExecutor` with `PartnerTokenCredentials`,
  which never replays: a 401 there means a bad Partner Token), `StaticAccessToken` (a provider whose `refresh()`
  returns the same token), `BearerCredentials` (a provider as `Authorization: Bearer`), `JwtExpiry`.
- Replay: after a 401 `BearerCredentials` first asks the provider for its current token (another worker may already
  have stored a new one), then forces `refresh()`; it replays only with a token different from the rejected one, so a
  static token is never replayed. A second 401 propagates as `AuthenticationException`. Mutations are replayed too:
  SDK_SPEC 1.4 treats 401 as "rejected before execution" (the JWT check runs before the resolver). Owner confirmed
  2026-10-31 that this is the one exception to CLAUDE.md rule 2 ("mutations are never retried").
- Expiry: lifetime = `exp - iat` when both are integers, `iat >= 0` and `exp > iat`, otherwise `exp - now`; it is
  counted from the local clock, so a skewed host clock does not shorten it. Missing, unparsable or non-positive -> 24 h
  (the documented lifetime), and no lifetime is taken as longer than 24 h (this also keeps the arithmetic in integer
  range). The token is refreshed 5 minutes before that point and kept at least 10 minutes, so a token shorter than
  the margin is not exchanged on every call; if the server rejects it earlier, the 401 path renews it. All of it is in
  whole seconds (JWT claims are), so the bounds hold to within 1 s of a sub-second clock.
- `Psr16TokenStore` keys: caller-given and validated against the PSR-16 minimum (`A-Z a-z 0-9 _ .`, up to 64).
  `forPartnerToken()` derives `whitemarket_sdk.access_token.<first 32 hex of sha256(partner token)>`, so two Partner
  Tokens sharing a cache never read each other's JWT. The cache entry holds the JWT in plain text (whoever reads the
  cache could also read the token from memory of a worker); its TTL ends at the token's expiry. A malformed entry is a
  cache miss.
- Single-flight: PHP runs one request at a time per process with a blocking PSR-18 client, so in-process de-dup is
  moot; across processes the shared store is the only coordination (no lock).

Dependency: `psr/simple-cache` `^2.0 || ^3.0` (interfaces only, no code, no transitive dependencies; 2.0 is the
first release with native parameter types, PHP >= 8.0). Needed by `Psr16TokenStore`; SDK_SPEC 2 names the PHP PSR-16
adapter. A user without PSR-16 pays one interface package.

## Consequences

- `Credentials` gains `headersAfterRejection()`; T2.6 wires `partnerToken` / `accessToken` / `tokenProvider` /
  `tokenStore` into `BearerCredentials` and the default store.
- Fiber-based PSR-18 clients could interleave two refreshes in one process; accepted with the cross-process case.
- A cache backend that throws (other than PSR-16 key errors) makes the request fail; nothing is swallowed.

## Sources

- SDK_SPEC 1.4, 2, 3.1; WHITEMARKET_API.md 3.
- PSR-16: https://www.php-fig.org/psr/psr-16/ (key characters and length, `set()` TTL, `get()` default).
- psr/simple-cache 3.0.0 / 2.0.0 tags: https://github.com/php-fig/simple-cache/tree/3.0.0
