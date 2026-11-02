# 0001. Code generation of the PHP layer

- Status: accepted 2026-10-30 (owner): option 2, own PHP generator; design in 0002
- Date: 2026-10-30

## Context

SDK_SPEC 1.1 requires a generated layer: every object/input/enum type of `schema/partner.graphql`, one typed operation per
root field, enums with an unknown fallback. CLAUDE.md fixes the constraints:

- the high-level transport owns auth, retry and errors, so generated operations must go through **our** client instance;
- no public mutable state, no static mutable state;
- unknown enum values never throw (rule 5);
- runtime dependencies are PSR interfaces plus the minimum; each one justified in an ADR (rule 10);
- PHP 8.2 to 8.5.

Operation documents (`graphql/operations/**`, `graphql/fragments/**`) are already produced by the shared Node generator
(SDK_SPEC 1.2); this ADR is only about turning schema + documents into PHP.

## Options

### 1. spawnia/sailor v1.5.0 (default candidate in CLAUDE.md)

Spike in `var/spike-sailor` (Composer platform PHP 8.2), 2026-10-30:

| Requirement | Result | Evidence |
|---|---|---|
| PHP 8.2-8.5 | pass | `require.php: ^7.4 \|\| ^8` [observed Packagist] |
| Custom scalars | pass | `EndpointConfig::configureTypes()` [docs] |
| Interfaces/unions | pass | `Convert/PolymorphicConverter.php` [code] |
| Transport per client instance | **fail via the documented API** (workaround below) | `Operation::fetchResponse()` takes the client from `self::$clients[static::class]` (static, per operation class) and the endpoint config from `Configuration::$configs` (static); `Operation::setClient()` is a static setter [code `src/Operation.php:82-96,133-136`, `src/Configuration.php:8`]. Two `WhiteMarketClient` instances with different tokens would share one global client. |
| Unknown enum values with native enums | **fail with the stock config** (workaround below) | `NativeEnumConverter::fromGraphQL()` calls `::from($value)`; probe: known value decodes, `'NEW_SERVER_VALUE'` throws `ValueError` [observed]. String-constant enums pass values through but are not native enums. |
| Runtime dependency footprint | **fail** | generated classes extend `Spawnia\Sailor\Operation`/`Result`/`ObjectLike` at runtime, and `spawnia/sailor` requires `symfony/console`, `symfony/var-exporter`, `nette/php-generator`, `thecodingmachine/safe`, `webonyx/graphql-php` [observed Packagist]. Every SDK user would install a CLI framework and a code generator in production. |

Independent verification (adversarial-verifier, 2026-10-30, experiment in `var/spike-sailor/verifier_gen/`):

- Transport per instance: the documented path fails, but a workaround works. The SDK skips the generated static `execute()`,
  sends `$op::document()` through its own transport, and decodes with the public `Result::fromResponse(Response::fromJson(...))`.
  Costs: the typed `execute(...)` signature is lost (own typed wrappers needed), variables encoding relies on the
  protected static `Operation::variables()` or is done by the SDK, and it breaks if Sailor makes operations final or `variables()` private.
- Unknown enum values: the stock `NativeEnumTypeConfig` throws, but overriding `EndpointConfig::enumTypeConfig()` with a
  subclass that adds an `UNKNOWN__` case and a tolerant converter works (verified by real Sailor codegen). The raw unknown
  value is lost unless a full custom `TypeConfig` wrapper is written; `UNKNOWN__` is also added to introspection enums.
- Runtime footprint: **confirmed failure**. No runtime-only package exists; avoiding the CLI dependencies means vendoring or forking ~20 runtime files.
- Also found: `ObjectLike::fromGraphQL` throws on response properties outside the selection (`src/ObjectLike.php:115-118`).

Net: Sailor is usable only with two workarounds against its documented API, and it still adds five libraries (CLI framework,
code generator) to every user's production tree.

### 2. Own generator (PHP, over webonyx/graphql-php, dev dependency only)

A small generator in `tools/codegen-php/` (run by `make codegen`) parses the schema and the operation documents with
`webonyx/graphql-php` and emits `final readonly class` types, backed enums with an `Unknown`-safe decoder, and one
operation class per document. Generated code depends only on a small hand-written runtime in `src/` (decoder helpers, an
`OperationExecutor` interface implemented by our transport). Zero additional runtime dependencies; `webonyx/graphql-php`
stays in `require-dev`. Cost: the generator is our code (tests, maintenance).

### 3. Own generator in Node (reusing the pinned `graphql` package of the operation generator)

Same output as option 2, one GraphQL parser in the repository. Cost: PHP code emitted from JavaScript; the generator cannot
be tested with PHPUnit or analysed with PHPStan.

## Decision

Option 2 (owner decision 2026-10-30). Design: [0002](0002-generated-layer-and-transport.md).

## Consequences

Option 2: no third-party runtime code in the generated layer; full control over unknown-value handling and decimals
(`Money.value` stays a string); the generator needs its own tests (golden files over a small fixture schema) and must be
deterministic for `make codegen-check`.

## Sources

- Sailor README v1.5.0: https://github.com/spawnia/sailor/blob/v1.5.0/README.md
- Sailor source v1.5.0 (installed in the spike): `src/Operation.php`, `src/Configuration.php`, `src/Convert/NativeEnumConverter.php`
- Packagist metadata: https://repo.packagist.org/p2/spawnia/sailor.json
- webonyx/graphql-php: https://github.com/webonyx/graphql-php (version pinned when the generator is written)
