# 0002. Generated layer, GraphQL runtime and HTTP transport

- Status: accepted by the owner 2026-10-30 (own generator, Guzzle transport, lowest possible PHP); details open for review
- Date: 2026-10-30
- Follows: [0001](0001-codegen.md) (Sailor rejected: runtime dependency footprint)

## Owner requirements (2026-10-30)

1. Own code generator instead of Sailor.
2. Transport on Guzzle with preconfigured defaults (timeouts etc.) and a clear way to replace the HTTP client.
3. Above the transport: queries, mutations, enums, types that use PHP features as much as possible.
4. The lowest possible minimum PHP version, for the widest reach.

## Minimum PHP version: 8.1

| Version | What we would lose | Verdict |
|---|---|---|
| 8.2 (current) | nothing | narrower reach |
| **8.1** | `readonly` classes (use `readonly` promoted properties instead: same immutability), DNF types, standalone `null`/`false`/`true` types | **chosen**: native backed enums, readonly properties, `never`, `new` in initializers, first-class callables, pure intersection types are all 8.1 |
| 8.0 | native enums, readonly properties | no: enums and immutability are the core of the generated layer |
| 7.4 | union types, `match`, constructor promotion, named arguments | no |

Consequences (to apply in the implementation session):

- `composer.json`: `"php": "^8.1"`, `config.platform.php = 8.1.0`; CI and `make matrix` add 8.1.
- PHP 8.1 is past upstream security support (php.net lists 8.2+ as supported on 2026-10-30). SDK_SPEC 0 says
  "nothing older than upstream-supported": recorded in `docs/DEVIATIONS.md`, owner decision.
- Dev tools must resolve for 8.1: PHPUnit 10.5 line (11 needs 8.2), PHPStan/Rector/php-cs-fixer unchanged, Infection stays isolated.
- `CLAUDE.md` changes (human-reviewed zone): "PHP 8.2+" -> "PHP 8.1+"; "Value objects are `final readonly class`" ->
  "`final class` with `readonly` promoted properties"; runtime dependency list gets Guzzle (below).
- WebSocket library for P6: `phrity/websocket` 4.x needs PHP 8.2; 3.8.x supports `^8.1` [observed Packagist]. Decided in the P6 ADR.

### Amendment 2026-10-31: minimum raised to 8.2

Owner decision after the finding below; the 8.1 row of the table above is superseded.

- Finding [observed 2026-10-31, `php:8.1-cli` 8.1.34, valgrind with `USE_ZEND_ALLOC=0`, `pcre.jit=0`, no extensions]:
  when an exception is thrown while the arguments of a `new self(...)` that pass first-class callables
  (`X::fromValue(...)`) are evaluated, inside a decoder called from another pending `new self(...)` (the shape of every
  generated `fromValue()`), the engine frees the same array twice (`zend_array_destroy`, invalid read and write).
  The heap is corrupted silently; a later allocation crashes the process (`make matrix`: segfault in the unit tests,
  `GoldenOutputTest` order-dependent). In the SDK this fires on every `DecodeException` thrown by generated code, that is
  on any malformed server response. 8.2, 8.3, 8.4 and 8.5 run the same pure-PHP repro without errors. Replacing the
  first-class callables (arrow function, array callable, string callable, `Closure::fromCallable`) avoids it on 8.1.
- 8.1 is security-only upstream, so no engine fix is coming. Rejected alternative: emit arrow functions instead of
  first-class callables (verified on the golden code); the owner chose to drop 8.1 instead.
- Consequences: `composer.json` `"php": "^8.2"`, `config.platform.php = 8.2.0`; CI and `make matrix` start at 8.2;
  PHPStan `phpVersion.min` 80200. SDK_SPEC 0 needs no PHP exception any more (8.2 is supported upstream on 2026-10-31),
  and `#[\SensitiveParameter]` is a built-in on every supported version.
- Dev tools: PHPUnit moves to the 11.5 line (owner decision 2026-10-31; 11 needs PHP 8.2, 12 needs 8.3 [docs
  https://phpunit.de/supported-versions.html]). `phpunit.xml.dist`: `restrictDeprecations` (deprecated in 11.1) ->
  `ignoreIndirectDeprecations`, as `--migrate-configuration` produces.

## Layers

```
 user code
    |
 WhiteMarketClient (hand-written, P2-P6)          market/buying/deals/... ; owns configuration
    |
 Generated\GeneratedClient (generated)            one typed method per operation: marketList(...), marketBuy(...)
    |   uses Generated\Operation\*, Generated\Type\*, Generated\Enum\*, Generated\Input\*
    |
 GraphQL\OperationExecutor (hand-written interface)
    |   GraphQL\GraphQLExecutor: builds {query, operationName, variables}, decodes {data, errors},
    |   maps errors (GraphQLError, NotFoundError by extensions.category), knows QUERY vs MUTATION
    |
 Transport\HttpTransport (hand-written)           headers, auth, retry (queries only), 401 refresh+replay,
    |                                             size limit, redacted PSR-3 logging, status -> exception
    |
 Psr\Http\Client\ClientInterface (PSR-18)
    |
 GuzzleHttp\Client (default, built by Transport\GuzzleClientFactory)  |  any PSR-18 client the user injects
```

Rule: everything below `GeneratedClient` is per instance. No static mutable state anywhere, so two clients with different
tokens in one process never share a transport (the reason Sailor was rejected).

## Transport

### Default client: Guzzle

`guzzlehttp/guzzle` becomes a runtime dependency (`^7.9 || ^8.0`, so projects on either major can install the SDK),
together with `guzzlehttp/psr7` (PSR-17 factories; declared explicitly because rule 10 forbids using transitive
dependencies). `php-http/discovery` is no longer needed.

The PSR packages are declared explicitly for the same reason (the high-level layer depends on the interfaces only):

| Package | Constraint | Why |
|---|---|---|
| `psr/http-client` | `^1.0` | PSR-18 `ClientInterface`: the seam where any HTTP client is injected |
| `psr/http-factory` | `^1.1` | PSR-17 request and stream factories used to build requests without naming a PSR-7 implementation |
| `psr/http-message` | `^1.1 \|\| ^2.0` | PSR-7 request, response and stream types in every transport signature |
| `psr/log` | `^2.0 \|\| ^3.0` | PSR-3 `LoggerInterface` for the redacted transport logging |
| `psr/simple-cache` | `^2.0 \|\| ^3.0` | PSR-16 `CacheInterface` for the optional shared access-token store |

`Transport\GuzzleClientFactory::create(TransportOptions $options): ClientInterface` builds the client with:

| Guzzle option | Default | Why |
|---|---|---|
| `connect_timeout` | 10 s | SDK_SPEC 1.3 |
| `timeout` | 30 s (GraphQL); none for export downloads | SDK_SPEC 1.3 |
| `read_timeout` | 60 s for export downloads | idle timeout, SDK_SPEC 1.3 |
| `http_errors` | `false` | statuses are mapped by `HttpTransport`, not by Guzzle exceptions |
| `allow_redirects` | `false` for GraphQL; for exports `max 3`, `strict`, `protocols ['https']`, same-host only | never forward `Authorization` to another host (SDK_SPEC 8) |
| `verify` | `true`, not configurable | no TLS-off switch (SDK_SPEC 8) |
| `decode_content` | `true` + `Accept-Encoding: gzip` | SDK_SPEC 1.3 |
| `stream` | `true` for exports and SSE | streaming parsers (P5, P6) |

All defaults live in one immutable `TransportOptions` value object (`connectTimeout`, `requestTimeout`, `idleTimeout`,
`maxResponseBytes = 32 MiB`, `userAgentSuffix`, `baseUrl`, `graphqlPath`); every option is a named constructor argument.

### Replacing the HTTP client

```php
// 1. Defaults: Guzzle with the table above.
$client = WhiteMarketClient::create(partnerToken: $token);

// 2. Tune defaults, keep Guzzle.
$client = WhiteMarketClient::create(partnerToken: $token, transport: new TransportOptions(requestTimeout: 60.0));

// 3. Own Guzzle instance (proxy, handler stack, middleware): SDK uses it as is.
$client = WhiteMarketClient::create(partnerToken: $token, httpClient: new GuzzleHttp\Client([...]));

// 4. Any PSR-18 client (Symfony Psr18Client, Buzz, a test double); PSR-17 factories optional (Guzzle PSR-7 by default).
$client = WhiteMarketClient::create(partnerToken: $token, httpClient: $psr18, requestFactory: $f, streamFactory: $f);
```

With an injected client the SDK cannot set timeouts (PSR-18 has no timeout API): documented in the README; the client
logs one PSR-3 notice at construction. Tests use an in-memory PSR-18 fake (no network), the same path users take in (4).

## Generator

Location `tools/codegen-php/` (namespace `WhiteMarket\Sdk\Tools\Codegen`, autoload-dev, excluded from the dist archive).
Dev dependency `webonyx/graphql-php` (parses the schema and the documents, resolves types). Run by `make codegen` after the
Node operation generator; `make codegen-check` diffs `src/Generated/` too.

```
schema/partner.graphql ----------+
graphql/operations/**/*.graphql -+--> tools/codegen-php (webonyx AST + schema) --> src/Generated/
graphql/fragments/*.graphql -----+        deterministic: sorted, stable names, LF, "DO NOT EDIT" header
```

### Output (namespace `WhiteMarket\Sdk\Generated`)

| Schema element | PHP | Notes |
|---|---|---|
| enum | `enum MarketDealStatus: string { case COMPLETED = 'COMPLETED'; ... }` | every value, deprecated ones marked `@deprecated` |
| enum value in a response | property type `MarketDealStatus\|UnknownEnumValue` | `tryFrom()`; an unknown value becomes `UnknownEnumValue{enum, value}` and keeps the raw string (rule 5). `match` forces callers to handle it |
| input object | `final class MarketProductSearchInput implements InputObject` with `public readonly` promoted, nullable, defaulted properties; `toArray()` omits nulls | deprecated input fields kept, `@deprecated` in PHPDoc (SDK_SPEC 1.1) |
| object selection | per-operation result classes `Operation\Query\MarketList\...` with `public readonly` properties, typed by the schema (non-null -> non-nullable) | per selection, not per schema type: the same type is selected differently at different depths |
| fragment `<Type>Fields` (leaf fields only) | shared class `Fragment\MoneyFields`; a selection that is exactly the fragment reuses it | e.g. every `Money` is one class |
| interface / union | a PHP interface per selection + one class per possible type, chosen by `__typename`; unknown `__typename` -> `UnknownTypeValue{typename, raw}` | new server types never throw |
| list / non-null | `list<T>` in PHPDoc, `array` natively; nullability from the schema | PHPStan level max checks the shapes |
| scalars | `String`, `Uuid`, `ID` -> `string`; `Int` -> `int`; `Boolean` -> `bool`; `DateTime` -> `DateTimeImmutable`; `Float` -> `float` | money and wear are `String` in the schema (stay strings, decimal-safe); the only `Float` fields are `fade` / `csgoFade*` percentages |
| operation | `final class MarketList` with `DOCUMENT`, `NAME`, `TYPE = OperationType::Query`, typed static `variables(...)` and `decode(array $data): MarketList\Result` | document = operation + used fragments; no `Operation` interface (see Implementation notes) |
| client | `GeneratedClient` with one method per operation: `marketList(?ForwardPaginationInput $forwardPagination = null, ?MarketProductSearchInput $search = null): MarketListResult`, plus `execute(string $document, array $variables = [], ?string $operationName = null): array` | constructor takes `OperationExecutor`; no statics |

Decoding is strict about shape (missing required field -> `DecodeException` (`Errors\DecodeException`, SDK_SPEC 1.5 `DecodeError`) with the JSON path) and tolerant about additions
(unknown fields ignored, unknown enum values and typenames wrapped).

### Generator tests

- Golden files: a small fixture schema + documents under `tests/fixtures/codegen/` covering every row of the table; the
  generated PHP is compared byte for byte and executed (decode real-looking JSON, encode inputs).
- `make codegen-check` on the real schema in CI.
- PHPStan level max runs over `src/Generated/` too (the generated code must pass the same strictness).

## Open points for review

1. Per-operation result classes produce many classes (estimate: several hundred). Alternative: one class per schema type
   with every object field nullable. Chosen per operation for exact nullability; revisit if the count hurts.
2. Explicit `null` in inputs (GraphQL distinguishes omitted from null): not needed by any partner operation found so far;
   inputs omit nulls. If one appears, add an `Omitted` sentinel.
3. Guzzle version range `^7.9 || ^8.0`: both majors tested in CI (lowest/highest dependency jobs).

## Implementation notes (2026-10-30, P1)

- Runtime used by the generated code lives in `src/GraphQL/`: `OperationExecutor`, `OperationType`, `InputObject`,
  `Decode` (composable decoders `callable(mixed $value, string $path): T`), `Encode`, `RawDocument`,
  `UnknownTypeValue`, `DecodeException` (`Errors\DecodeException`, SDK_SPEC 1.5 `DecodeError`) (unchecked in PHPStan: decoders compose as closures). `UnknownEnumValue` lives in `src/Models/` (`Models\UnknownEnumValue`) because the high-level API exposes it.
- `Decode`, `Encode` and `RawDocument` are `@internal` (called by generated code only); the other runtime types are public
  because they appear in generated signatures and catch blocks.
- Generated code uses fully qualified names and no imports, so no generated class name (`Value`, `Decode`, ...) can
  clash with a runtime class; it is PER-CS clean and PHPStan level max clean
  as emitted. `make fmt-check` and `make stan` cover `src/Generated/`, so a generator change that emits invalid or
  unformatted PHP fails CI instead of being "fixed" by a tool afterwards. Rector skips generated code.
- Input objects encode through `Encode::inputObject($this)` (public properties via `get_object_vars()`), so deprecated
  input fields stay usable and marked `@deprecated` without the generated code reading them by name.
- An interface without a single implementation in the schema (the partner schema hides all `MarketOrder*`
  implementations) decodes to `UnknownTypeValue` only; no empty interface is generated for it.
- `FileUpload` input fields are not generated: a multipart upload cannot travel in a JSON request, and the only one
  (`PersonStoreInput.customAvatar`) has a JSON alternative (`encodedCustomAvatar`).
- `GeneratedClient::execute($document, $variables, $operationName)` runs a raw document. Its operation type comes from
  `RawDocument::operationType()`: only a document that is clearly a single query is a query; anything else, including a
  document that mentions `mutation` anywhere, is executed as a mutation and is never retried (CLAUDE.md rule 2).
- Golden test: `tests/fixtures/codegen/` (synthetic schema with one example of every construct) -> `golden/`,
  compared byte for byte and executed; `make codegen-golden` regenerates it after a deliberate generator change.
- Deviations from the "Output" table (CLAUDE.md rule 17): there is no `Operation` interface, because nothing consumes
  operations polymorphically (the client calls each operation class directly, the executor takes plain strings);
  result root classes are `<Operation>\Result` so their children sit next to them (`MarketList\Edges`);
  `execute()` takes an optional `$operationName` for documents with several operations.

## Implementation notes (2026-10-31, T2.4)

- `decode_content` is `false`, not `true` as in the table above: `HttpTransport` sends `Accept-Encoding: gzip` and
  decompresses itself (`inflate_add`, 8 KiB reads), so `maxResponseBytes` counts decompressed bytes (a gzip bomb stops
  at the limit) and an injected PSR-18 client that does not decompress works the same. Hence `ext-zlib` in `require`.
- The PSR-3 log never receives headers or bodies, not even redacted ones: operation name, type, attempt, status,
  duration, retry delay. Stricter than SDK_SPEC 1.3 (which allows redacted debug output); nothing to redact means
  nothing to get wrong.
- `psr/log` is `^2.0 || ^3.0`: 1.x (no native types) is not supported (owner decision 2026-10-31).
- Exceptions of the HTTP client are not chained into `TransportException`: they hold the request with its
  `Authorization` header (SDK_SPEC 8, no credentials in attached data). The message names the exception class.
- `TransportOptions` has no `idleTimeout` yet; it arrives with export downloads (P5).
- Auth enters through `Auth\Credentials` (headers per operation name); `StaticAccessToken` is the first
  implementation, the Partner Token provider with refresh and 401 replay follows in T2.5.
- The generated-layer tests keep `FakeOperationExecutor` (the generated layer's own boundary is the `OperationExecutor`
  interface); the HTTP path is covered end to end by `GraphQLExecutorTest::runsAGeneratedOperationOverHttp` with the
  fake PSR-18 client `tests/Support/FakeHttpClient`.
- Mutations go through a second Guzzle client (`GuzzleClientFactory::createForMutations()`, `HttpClients::$mutation`;
  `HttpTransport` takes both clients as one required `HttpClients`, so leaving the mutation client out is a type error) with `CURLOPT_FRESH_CONNECT` and `CURLOPT_FORBID_REUSE`. libcurl resends a request on its own when
  a *reused* connection dies before any response byte (`Curl_retry_request`, `conn->bits.reuse`, curl `lib/transfer.c`);
  a server that reads a mutation and then drops the idle keep-alive connection made the SDK send it twice
  (`GuzzleClientFactoryTest::sendsEachMutationOnceWhenTheServerClosesTheConnectionWithoutAResponse`, local server
  `tests/fixtures/servers/loopback.php`). Queries keep connection reuse. Cost: one TCP + TLS handshake per
  mutation. Guzzle itself resends up to 3 times when libcurl ends with errno 0 (or 65) and no complete response
  (`CurlFactory::finishError()` -> `retryFailedRewind()`, Guzzle 7.16.1 `CurlFactory.php:1073, 2453-2486`). A server
  that sends only a status line and closes triggers it (libcurl 8.19.0, PHP 8.5.4: one `sendRequest` delivered the
  mutation 3 times, on fresh connections; the curl options above do not reach this path). The mutation client wraps
  the body in a copy behind `GuzzleHttp\Psr7\NoSeekStream` (a Guzzle middleware): `retryFailedRewind()` rewinds a body
  that has been read before resending, the rewind throws, and Guzzle rejects the request instead. This relies on
  Guzzle's rewind check, not on its private `_curl_retries` option; the loopback test pins it against Guzzle upgrades.
  An adversarial check (about 130 runs over plain HTTP/1.1 on loopback: close before a response, status line only,
  partial or truncated headers, RST, timeout, 100/103 then close, 417 before or during the upload, bodies of 23 B to
  1.2 MB, non-seekable bodies) found no complete body delivered twice. Known edges, none a duplicate mutation: a body
  with `Expect: 100-continue` that the server answers with 417 mid-upload is resent in full by libcurl, but only after
  the server rejected the partial copy (the SDK's seekable string bodies under 1 MB carry no `Expect`); an empty body
  is resent 3 times because Guzzle rewinds only a body that was read (SDK requests always have a body).
  Not checked: TLS (close_notify vs abrupt close), proxies, HTTP/2, libcurl versions other than 8.19.0. The query client keeps Guzzle's resend: queries
  are safe to repeat, though these resends are invisible to `RetryPolicy` and the log. A user-supplied PSR-18 client
  is trusted not to resend.
- Trust boundary for credentials (owner decision 2026-10-31): injected PSR-7, PSR-17 and PSR-18 implementations
  receive the credential headers and the body by design and are trusted with them. `HttpTransport` guarantees what it
  controls: no credential in its own messages or frame arguments (`#[\SensitiveParameter]`), anything the client
  throws from `sendRequest()` and anything the request throws while a credential header is set leaves `post()` as an
  unchained exception without the value. Failures outside the PSR contracts (a throwing destructor, a throwing
  response accessor, a failing stream factory) are not wrapped; see DEVIATIONS "Accepted limitations".
  `ext-curl` is in `require` (owner decision 2026-10-31): Guzzle without it falls back to PHP streams, and the SDK
  states one transport behaviour instead of two.

## Implementation notes (2026-10-31, T3.1): high-level mappers take object shapes

- Fact: the generator emits one class per selection per operation, so one GraphQL type reaches the high-level layer as
  many classes with the same fields (`CSGOInventoryItem`: 28 classes; `MarketProduct`: 8 full selections), and fields
  typed by a GraphQL interface (`item`, `description`, `params`) are empty marker interfaces per operation.
- Decision: `Models\ModelMapper` takes PHPStan object shapes (`@phpstan-type ProductSource object{...}` and so on), so
  PHPStan checks every call site against the fields the mapper reads and a new operation needs no change in the mapper.
  Interface-typed fields are narrowed at run time by `property_exists` behind `@phpstan-assert-if-true` methods, against
  the one implementation the SDK knows (`CSGOInventoryItem`, `CSGOSteamItem`); anything else maps to null (rule 5).
  [observed] PHPStan 2.3.4 accepts a generated readonly class for an `object{...}` parameter and narrows through the
  assert; an access without the narrowing is reported. PHPStan treats an object shape as closed: optional properties
  are read before the shape assertion (see `ModelMapper::inventoryItem()`).
- Rejected: a native union of the generated classes per mapper (up to 28 classes, edited for every new operation); the
  generator typing a single-implementation interface as its class (every mapper breaks when the schema adds a second
  item type, `SteamApp` already lists DOTA2 and TF2); deep hand-written fragments through `graphql/overrides/` (the
  shared operation generator validates overrides against generated fragments only, SDK_SPEC 1.2).

