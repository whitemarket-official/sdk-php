# Contributing

## Setup

Requirements: PHP 8.2+, Docker (Composer, coverage and the PHP version matrix run in containers), Node.js (codegen tools), GNU Make.

```bash
make install   # composer install
make check     # everything CI runs
```

Run `make help` for all targets. Noisy targets print only errors and a summary; the full log is in `var/qc/<target>.log`
(`VERBOSE=1` shows everything).

## Live smoke

`make live-smoke` calls the real partner API with read-only queries only (SDK_SPEC 6.4): every generated query with minimal
variables, one export file, and one `market_products_updates` event over WebSocket and over SSE (up to 120 s each). It reads the
token from `WHITEMARKET_PARTNER_TOKEN` (exit 2 when missing), never runs a mutation (the SDK's own `auth_token` exchange aside) and
writes `var/live-smoke/report.md` with step names, results and timings only. CI runs it weekly and on demand
(`.github/workflows/live-smoke.yml`, secret `WM_PARTNER_TOKEN` of a dedicated test account). A new generated query makes
`tests/livesmoke` fail until `tools/live-smoke/src/QueryCatalog.php` covers it.

## Rules

- `src/Generated/`, `schema/`, `graphql/operations/`, `graphql/fragments/` are generated: never edit them by hand.
  Regenerate with `make schema` / `make codegen`. Hand-written selection overrides go to `graphql/overrides/`.
  The generator itself is `tools/codegen-php/`; after changing it run `make codegen-golden` and review the golden diff
  under `tests/fixtures/codegen/golden/`. Generated PHP must pass `make stan` and `make fmt-check` as emitted.
- New behaviour and every bug fix come with tests. A bug fix starts with a failing test.
- Mutations are never retried automatically. Money and wear values are exact decimals, never floats.
- Never commit real tokens, credentials, IP addresses or personal data, including in test fixtures.

## Commit convention

```
type(scope): short title

description

Refs: #<issue>
```

Types: `feat`, `fix`, `chore`, `docs`, `style`, `refactor`, `test`, `perf`, `build`, `ci`.
Breaking change: `type(scope)!:` and a `BREAKING CHANGE:` footer. Branches: `feature/<issue>-<slug>`, `fix/<issue>-<slug>`.
Pull requests target `main`.
