# 0006. Pluggable JSON stream decoders: json-machine, simdjson, yajl_stream

- Status: accepted (owner decision 2026-11-01: an option with `Auto` as the default, simdjson in batches over the
  SDK's own scanner, yajl_stream fed through a stream wrapper)
- Date: 2026-11-01

## Context

`streamProducts` reads about 4.8M JSON rows (WHITEMARKET_API.md 8). ADR 0004 chose `halaxa/json-machine`, which is
pure PHP and the slowest part of the stream. The WhiteMarket backend streams the same kind of dumps with two optional
PHP extensions and falls back to json-machine (`whitemarket_backend` `src/SkinProvider/Service/SkinProviderRequestService.php:494-501`,
`src/Json/ChunkedJsonArrayParser.php`): `ext-yajl_stream` (libyajl SAX parser in C, about 5x faster than json-machine
measured with `php -n`) and `ext-simdjson` (whole-document decoder; in batches over a PHP element scanner about
1.5-1.7x faster; as a per-item json-machine decoder about 4%).

Facts that shape the design:

- `ext-yajl_stream` 0.1.0 (`vlydev/php-yajl-stream`): `YajlStream\ArrayParser(string $filePath, ...)` opens the path
  with `php_stream_open_wrapper()` (`yajl_stream.c`), so a userland stream wrapper works [observed 2026-11-01, PHP
  8.2, through `BodyStreamWrapper`]. An integer beyond the 64-bit range becomes a float; a top-level object or scalar
  yields nothing; bytes after the array are not read.
- `ext-simdjson` 4.0.0 (PECL): `simdjson_decode(string, bool $assoc, int $depth)`, no big-int option. An integer above
  `PHP_INT_MAX` comes back as a float without an error [observed 2026-11-01, PHP 8.2; the stub's "out of range
  integer" exception did not fire for 18446744073709551616].
- Both install with `mlocati/php-extension-installer` 2.13.1 on PHP 8.2-8.5 (`data/supported-extensions`)
  [observed: built on 8.2 and 8.5]. Neither has a Windows build of a current version; neither is a Composer package.
- CLAUDE.md rule 3: 64-bit ids never pass through a type that loses precision.

## Decision

1. Public enum `Export\JsonStreamDecoder` (`Auto`, `JsonMachine`, `Simdjson`, `YajlStream`) and the option
   `ExportOptions::$jsonStreamDecoder`, default `Auto`: yajl_stream when loaded, else simdjson, else json-machine
   (the backend's order). Naming a decoder whose extension is not loaded is an argument error when the options are
   built; `JsonStreamDecoder::isAvailable()` (public) lets a caller check first. `resolve()` (Auto to the concrete
   decoder) is `@internal`. CSV is not affected.
2. Internals in `src/Export/Rows/`: a pull interface `RowSource` (`next(): bool`, `row()`, `close()`) with one
   implementation per decoder (`JsonMachineRows`, `BatchJsonRows`, `YajlStreamRows`) and `CsvRows`, wrapped by
   `RowIterator` (an `\Iterator`, not a generator, so a failure is thrown by the call that read the row; it also
   removed the `shipmonk.checkedExceptionInYieldingMethod` findings of T5.2). `streamProducts` returns
   `\Iterator<int, ProductRow>`.
3. All JSON sources share `JsonArrayChunks` (the first byte that is not white space must be `[`), so an object, a
   scalar or an empty body is a `DecodeException` for every decoder, not zero rows. `JsonArrayChunks` also counts the
   bytes handed to json-machine or libyajl since the last row and refuses the row ('a list element is too long') past
   128 KiB (it hands the parser 8 KiB slices, so a row is refused at the latest three slices past the cap), so a hostile row
   cannot exhaust memory (secure-coding LIMIT-1, owner decision 2026-11-01). The cap is 128 KiB, not 1 MiB, for
   every format, because decoded PHP arrays take up to about 110 times the JSON text (nested single-element
   arrays), so the worst accepted row or batch costs about 15 MiB of PHP memory, and a caller's `foreach` keeps the previous
   row alive while the next one decodes, so the peak is about two rows, about 31 MiB (owner decision: 128 KiB) [observed 2026-11-01,
   PHP 8.5]. White space around a row counts against the cap. A batch never holds more than the cap of text. A decode failure is never chained to the parser's or `json_decode()`'s
   exception: their messages or trace arguments hold server bytes.
4. simdjson: `JsonArrayScanner` (own, pure PHP: strings, escapes, depth) cuts raw elements; `BatchJsonRows` decodes
   up to 1000 elements / 128 KiB of text per `simdjson_decode()` call (an element that would cross it starts the
   next batch). When a batch fails (a bad or over-long element, a body that breaks off), the rows before the failing
   element are delivered first, so every decoder delivers the same rows before a failure (SDK_SPEC 3.10 rule 9). A batch whose text holds a run of 19 or more
   digits, or one simdjson refuses, is decoded with `json_decode(..., JSON_BIGINT_AS_STRING)`, so ids stay exact; a
   batch `json_decode()` refuses is decoded element by element to name the first bad row. The scanner also refuses
   data after the closing `]` and bounds one element to 128 KiB.
5. yajl_stream: `BodyStreamWrapper` (`whitemarket-export-body://<random id>`) serves the `BodyReader` chunks, so gzip,
   the `Content-Length` and gzip-end checks and closing on `break` stay in force and nothing touches the disk. A
   failure while reading is kept by the wrapper and rethrown as the original exception after the parser stops.
6. Extensions are `suggest`ed in `composer.json`; PHPStan reads `tools/phpstan/stubs/*.stub`; the dependency analyser
   ignores their symbols. The Docker images of `make matrix` / `make coverage` install both (pinned), the host PHP
   has neither, so both the extension paths and the fallback are tested.

## Consequences

- With `YajlStream` (and so with `Auto` where it is loaded) a row whose id is above `PHP_INT_MAX` fails with
  `DecodeException` (the mapper refuses a float id) instead of arriving as a digit string. Steam ids are far below
  2^63 today; a caller that needs the guarantee picks `JsonMachine` or `Simdjson` (DEVIATIONS).
- Data after the closing `]` is refused by `Simdjson` only; json-machine and yajl_stream do not read it (DEVIATIONS).
- `BodyStreamWrapper` keeps a static registry (PHP instantiates wrappers itself): a deviation from "no static mutable
  state" (DEVIATIONS), entries removed on close.
- Results of the same file can differ only in the two points above; the contract test runs every case against every
  available decoder.
- Benchmarks on the real file are part of T5.3.

## Sources

- `whitemarket_backend`: `src/Json/ChunkedJsonArrayParser.php`, `src/Json/JsonArrayElementScanner.php`,
  `stubs/yajl_stream.php`, `src/SkinProvider/TradeOn/docs/integration-notes.md` (benchmarks), read 2026-11-01.
- https://raw.githubusercontent.com/vlydev/php-yajl-stream/v0.1.0/yajl_stream.c (open, number handling)
- https://pecl.php.net/package/simdjson (4.0.0, 2022-10-19), https://github.com/crazyxman/simdjson_php (API)
- https://raw.githubusercontent.com/mlocati/docker-php-extension-installer/master/data/supported-extensions
- Probe runs in `php:8.2-cli` + installer 2.13.1, 2026-11-01 (scratch script, not committed).
