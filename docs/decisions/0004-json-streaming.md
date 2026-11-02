# 0004. Streaming the products export: halaxa/json-machine

- Status: accepted (owner decision 2026-11-01); extended by ADR 0006 (optional simdjson / yajl_stream decoders, json-machine stays the fallback)
- Date: 2026-11-01

## Context

SDK_SPEC 3.10 `streamProducts`: the products export is one JSON array of about 4.8M rows (WHITEMARKET_API.md 8),
far too large for `json_decode` of the whole body, which `prices()` does today (`src/Export/ExportMapper.php`).
Rows hold 64-bit ids as JSON numbers (`product_class_id`, `product_instance_id`, `product_asset_id`) and decimals as
JSON strings (`price`, `product_float`), so the per-row decoder must keep integers beyond `PHP_INT_MAX` lossless
(CLAUDE.md rule 3). The body already arrives as decoded (gunzipped) chunks from `BodyReader`, which bounds each step
and rejects trailing data. CLAUDE.md rule 10: a runtime dependency needs an ADR.

## Options

1. `halaxa/json-machine` 1.2.6. No runtime dependency besides PHP (`ext-json` only for the default decoder), Apache-2.0.
   `Items::fromIterable()` takes any iterable of string chunks, so a generator over `BodyReader` feeds it directly;
   memory is bounded by one item. `ExtJsonDecoder($assoc, $depth, $options)` calls `json_decode` per item with our
   options, so `JSON_BIGINT_AS_STRING` keeps ids exact. Errors: `JsonMachineException` base,
   `SyntaxErrorException`, `UnexpectedEndSyntaxErrorException extends SyntaxErrorException` (truncated stream),
   `PathNotFoundException`. Con: `"php": "7.2 - 8.5"` caps PHP below 8.6; releases are infrequent (last functional
   release 2025-12-05).
2. Hand-written splitter over `BodyReader` chunks: track string / escape / bracket depth, cut top-level array
   elements, `json_decode` each with `JSON_BIGINT_AS_STRING`. No dependency, no PHP cap; about 60 lines of parser we
   own, test, fuzz and mutation-test ourselves.
3. `pcrov/jsonreader` (needs an extension), `salsify/json-streaming-parser` (callback API, slower): rejected without
   a deeper look.
4. CSV export (`products/730.csv`, same rows): `fgetcsv` streaming, no dependency, every field a string. Kept as the
   additional `format: csv` path, not a replacement for JSON.

## Decision

Option 1, `halaxa/json-machine: ^1.2.6`, used only inside `src/Export/`:

- input: `Items::fromIterable()` over a generator of `BodyReader` chunks (no `fromStream`, which needs a raw
  resource and would bypass the body limits);
- decoder: `new ExtJsonDecoder(true, 512, JSON_BIGINT_AS_STRING)`; rows then go through the existing export mapper
  logic, so a bad row fails with `DecodeException` naming the row and field;
- every `JsonMachineException` is caught at the boundary and rethrown as `DecodeException` with our own message (no
  server bytes in messages); `TransportException` from the chunk generator passes through unchanged;
- the decoder's `InvalidResult` for a row is a `DecodeException`, never skipped silently.

## Consequences

- `streamProducts` keeps constant memory; a 1M-row memory test is part of T5.3.
- The PHP 8.5 cap: when PHP 8.6 enters `make matrix`, Composer will refuse to install unless upstream widened the
  range. Then either upstream releases a new range, or we switch to option 2 behind the same internal reader (the
  library is not visible in any public signature, so the switch is not a breaking change).
- Tests pin: an id above 2^63 arrives as a digit string; a truncated body and a body with a syntax error become
  `DecodeException`; a top-level object instead of an array is rejected.
- Composer runs in Docker (`composer:2.11.0`); `make deps` must see the package used directly.

## Sources

- https://raw.githubusercontent.com/halaxa/json-machine/1.2.6/composer.json (`require`, license; checked 2026-11-01)
- https://raw.githubusercontent.com/halaxa/json-machine/1.2.6/src/Exception/UnexpectedEndSyntaxErrorException.php
- https://raw.githubusercontent.com/halaxa/json-machine/1.2.6/src/Items.php, `src/JsonDecoder/ExtJsonDecoder.php`,
  `src/Parser.php` (read by a research subagent, not re-read by the author)
- https://github.com/halaxa/json-machine/blob/1.2.6/README.md
