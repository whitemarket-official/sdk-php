# Transport and auth checklist

Applies to transport, auth, token storage, secret type, logging, errors, retry, buying. Source: SDK_SPEC 1.3, 1.4, 1.5, 3.3, 8.

### [code] SECRET-1: no hardcoded credentials - blocker

```bash
git diff HEAD -- . ':!*.md' | grep -inE '(token|secret|password|bearer|api[_-]?key)\s*[:=]\s*["'\''][^"'\'']{12,}'
```
Fixtures use obvious placeholders (`test-partner-token`, a JWT with a synthetic payload). Real-looking value -> FAIL; if committed, it must be rotated, not just deleted.

### [code] SECRET-2: secrets wrapped and marked - blocker

Every parameter receiving a Partner Token, JWT, Steam token payload or proxy credential has `#[\SensitiveParameter]` and is stored as the `Secret` value object.
```bash
grep -rnE 'function .*\$(partnerToken|accessToken|token|secret|password)\b' src --include='*.php' | grep -v SensitiveParameter
```
`Secret::__toString`, `__debugInfo`, `__serialize`, `jsonSerialize` never return the value; a test asserts `***`.

### [review] REDACT-1: logs and errors never carry secrets or bodies - blocker

Headers `Authorization`, `X-partner-token` and `cf_connect` query values are always redacted in debug logs; request bodies of `auth_*` and `profile_token_set` are never logged; no request/response bodies logged by default. Exception messages contain no token and no request body (SDK_SPEC 1.5). Trace every new logger call and every `new ...Exception(` in the diff to its inputs. A redaction test exists for each new log site.

### [code] TLS-1: no TLS-off switch - blocker

```bash
grep -rniE 'verify_peer|verify_host|CURLOPT_SSL_VERIFY|verify.?=>.?false|insecure|skipTls|disableTls' src
```
Any option that disables certificate verification in the SDK -> FAIL. The user may inject their own PSR-18 client; the SDK itself never offers the switch.

### [review] REDIRECT-1: credentials never follow a redirect to another host - blocker

A redirect to a different scheme/host/port must not carry `Authorization` or `X-partner-token`. Check how redirects are followed (SDK code or the injected client's defaults) and that a test covers a cross-host redirect.

### [review] TOKEN-STORE-1: token store is safe - warning

Stored access tokens: PSR-16 keys are namespaced and do not contain the token; TTL derived from JWT `exp` minus the safety margin; JWT payload is decoded without trusting it for anything but `exp`. Never persists the Partner Token unless the user explicitly provides that store.

### [review] REFRESH-1: single-flight refresh and one replay - blocker

401 -> refresh once -> replay once (also for mutations). No loop: a second 401 becomes `AuthenticationError`. Concurrent callers trigger exactly one `auth_token` call; a test asserts it.

### [code] RETRY-1: mutations are never retried - blocker

Inspect every retry path (transport, helpers, pagination, reconciliation) touched by the diff. A mutation is sent once; only 401 replay is allowed. A test asserts exactly one request on 503 for a mutation.
```bash
git diff HEAD -- src | grep -nE '(retry|attempt|backoff|sleep)'
```

### [review] RECON-1: purchase reconciliation exactly as SDK_SPEC 3.3 - blocker

`customId` generated (UUIDv4) when absent and validated (max 100 chars) before sending; `market_buy` sent once; GraphQL error and 400/401/429 throw without reconciliation; only 5xx/timeout/transport error after the request may have been sent triggers `deal_item` then `deal_history_item` lookups by `customId`; nothing found -> `PurchaseOutcomeUnknownError(customId)`. Never "buy again".

### [code] MONEY-1: exact decimals, no float - blocker

```bash
git diff HEAD -- src | grep -nE '^\+.*\b(float|floatval|\(float\)|round\(|number_format)'
```
Money and wear values flow as decimal strings / decimal type end to end; 64-bit ids as `string`. A test with an id above 2^53 exists where ids are mapped.

### [review] ERR-1: branching on category, not message - warning

New error handling branches on `extensions.category` / HTTP status, never on `message` text (CLAUDE.md rule 4).

### [code] DEBUG-1: no debug output - blocker

```bash
git diff HEAD -- src | grep -nE '^\+.*\b(var_dump|print_r|dump\(|dd\(|error_log|echo )'
```
