<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Memory;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Export\ExportFormat;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Export\JsonStreamDecoder;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\GeneratorStream;
use WhiteMarket\Sdk\Tests\Support\SyntheticProducts;
use WhiteMarket\Sdk\Tests\Support\TestExport;

/**
 * SDK_SPEC 3.10 / plan T5.3: `streamProducts()` over a file of a million rows keeps one row in memory, whatever the
 * format, compression and JSON decoder. The body is produced lazily by a stream, so it is not in memory either; the only
 * memory that may grow is the SDK's own. Run with `make test-memory` (the `memory` suite, not part of `make check`).
 */
final class StreamProductsMemoryTest extends TestCase
{
    private const ROWS = 1_000_000;

    private const MAX_GROWTH_BYTES = 16 * 1024 * 1024;

    /**
     * @return iterable<string, array{JsonStreamDecoder, ExportFormat, bool}>
     */
    public static function variants(): iterable
    {
        foreach ([JsonStreamDecoder::Auto, JsonStreamDecoder::JsonMachine, JsonStreamDecoder::Simdjson, JsonStreamDecoder::YajlStream] as $decoder) {
            yield 'json, ' . $decoder->value . ', plain' => [$decoder, ExportFormat::Json, false];
            yield 'json, ' . $decoder->value . ', gzip' => [$decoder, ExportFormat::Json, true];
        }
        yield 'csv, plain' => [JsonStreamDecoder::Auto, ExportFormat::Csv, false];
        yield 'csv, gzip' => [JsonStreamDecoder::Auto, ExportFormat::Csv, true];
    }

    #[Test]
    #[DataProvider('variants')]
    public function aMillionRowsAreStreamedInConstantMemory(JsonStreamDecoder $decoder, ExportFormat $format, bool $gzip): void
    {
        if (!$decoder->isAvailable()) {
            self::markTestSkipped('The extension of the decoder "' . $decoder->value . '" is not loaded.');
        }
        $chunks = $format === ExportFormat::Csv ? SyntheticProducts::csv(self::ROWS) : SyntheticProducts::json(self::ROWS);
        $response = new Response(200, $gzip ? ['Content-Encoding' => 'gzip'] : [], new GeneratorStream($gzip ? SyntheticProducts::gzip($chunks) : $chunks));
        $client = TestExport::client(new FakeHttpClient($response), new ExportOptions(jsonStreamDecoder: $decoder));

        $count = 0;
        $firstMismatch = null;
        $first = $last = null;
        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();

        foreach ($client->streamProducts(730, format: $format) as $row) {
            if ($row->productId !== SyntheticProducts::productId($count)) {
                $firstMismatch ??= $count;
            }
            if ($count === 0) {
                $first = [$row->productId, $row->classId, $row->assetId];
            }
            $last = [$row->productId, $row->classId, $row->assetId];
            ++$count;
        }
        $growth = memory_get_peak_usage() - $before;

        self::assertSame(self::ROWS, $count);
        self::assertNull($firstMismatch, 'rows arrive in file order, first out of order at ' . ($firstMismatch ?? -1));
        self::assertSame([SyntheticProducts::productId(0), (string) SyntheticProducts::CLASS_ID_BASE, (string) SyntheticProducts::ASSET_ID_BASE], $first);
        $lastIndex = self::ROWS - 1;
        self::assertSame(
            [SyntheticProducts::productId($lastIndex), (string) (SyntheticProducts::CLASS_ID_BASE + $lastIndex), (string) (SyntheticProducts::ASSET_ID_BASE + $lastIndex)],
            $last,
        );
        self::assertLessThan(self::MAX_GROWTH_BYTES, $growth, \sprintf('peak memory grew by %.1f MiB', $growth / 1048576));
    }
}
