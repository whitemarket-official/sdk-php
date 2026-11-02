<?php

declare(strict_types=1);

/**
 * Benchmark of ExportClient::streamProducts() per JSON decoder and for CSV, plain and gzip, over synthetic rows
 * (`make bench-export ROWS=200000`; default one million). Not shipped (export-ignore in .gitattributes). The time
 * includes producing the synthetic body (and deflating it for gzip), which the first row, "generate only", measures
 * alone, so the decoder's own cost is the difference. Needs the dev dependencies (it uses tests/Support).
 */

use GuzzleHttp\Psr7\Response;
use WhiteMarket\Sdk\Export\ExportFormat;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Export\JsonStreamDecoder;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\GeneratorStream;
use WhiteMarket\Sdk\Tests\Support\SyntheticProducts;
use WhiteMarket\Sdk\Tests\Support\TestExport;

require __DIR__ . '/../../vendor/autoload.php';

$rows = isset($argv[1]) ? (int) $argv[1] : 1_000_000;
if ($rows < 1) {
    fwrite(\STDERR, "Usage: php tools/bench/export-decoders.php [rows]\n");
    exit(2);
}

/**
 * @return Generator<int, string>
 */
function body(ExportFormat $format, bool $gzip, int $rows): Generator
{
    $chunks = $format === ExportFormat::Csv ? SyntheticProducts::csv($rows) : SyntheticProducts::json($rows);

    return $gzip ? SyntheticProducts::gzip($chunks) : $chunks;
}

/**
 * @param Closure(): int $run returns the number of rows (or chunks) processed
 *
 * @return array{int, float, int}
 */
function measure(Closure $run): array
{
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();
    $start = hrtime(true);
    $count = $run();

    return [$count, (hrtime(true) - $start) / 1e9, memory_get_peak_usage() - $before];
}

/** @var list<array{string, bool, Closure(): int}> $cases */
$cases = [];
foreach ([false, true] as $gzip) {
    $cases[] = ['generate only (json)', $gzip, static function () use ($rows, $gzip): int {
        $bytes = 0;
        foreach (body(ExportFormat::Json, $gzip, $rows) as $chunk) {
            $bytes += strlen($chunk);
        }

        return $rows;
    }];
    $cases[] = ['generate only (csv)', $gzip, static function () use ($rows, $gzip): int {
        foreach (body(ExportFormat::Csv, $gzip, $rows) as $_) {
        }

        return $rows;
    }];
    $stream = static function (JsonStreamDecoder $decoder, ExportFormat $format) use ($rows, $gzip): int {
        $response = new Response(200, $gzip ? ['Content-Encoding' => 'gzip'] : [], new GeneratorStream(body($format, $gzip, $rows)));
        $client = TestExport::client(new FakeHttpClient($response), new ExportOptions(jsonStreamDecoder: $decoder));
        $count = 0;
        foreach ($client->streamProducts(730, format: $format) as $_) {
            ++$count;
        }

        return $count;
    };
    foreach ([JsonStreamDecoder::JsonMachine, JsonStreamDecoder::Simdjson, JsonStreamDecoder::YajlStream] as $decoder) {
        if ($decoder->isAvailable()) {
            $cases[] = ['json, ' . $decoder->value, $gzip, static fn(): int => $stream($decoder, ExportFormat::Json)];
        }
    }
    $cases[] = ['csv', $gzip, static fn(): int => $stream(JsonStreamDecoder::Auto, ExportFormat::Csv)];
}

printf("PHP %s, %s rows, simdjson: %s, yajl_stream: %s\n", \PHP_VERSION, number_format($rows), JsonStreamDecoder::Simdjson->isAvailable() ? 'yes' : 'no', JsonStreamDecoder::YajlStream->isAvailable() ? 'yes' : 'no');
printf("%-22s %-6s %12s %9s %10s\n", 'case', 'body', 'rows/s', 'seconds', 'peak MiB');
foreach ($cases as [$name, $gzip, $run]) {
    [$count, $seconds, $peak] = measure($run);
    if ($count !== $rows) {
        fwrite(\STDERR, sprintf("%s: %d rows instead of %d\n", $name, $count, $rows));
        exit(1);
    }
    printf("%-22s %-6s %12s %9.2f %10.2f\n", $name, $gzip ? 'gzip' : 'plain', number_format($rows / $seconds), $seconds, $peak / 1048576);
}
