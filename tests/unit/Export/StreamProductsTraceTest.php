<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Export\ExportMapper;
use WhiteMarket\Sdk\Export\JsonStreamDecoder;
use WhiteMarket\Sdk\Export\Rows\BatchJsonRows;
use WhiteMarket\Sdk\Export\Rows\CsvRows;
use WhiteMarket\Sdk\Export\Rows\JsonArrayChunks;
use WhiteMarket\Sdk\Export\Rows\JsonMachineRows;
use WhiteMarket\Sdk\Export\Rows\RowIterator;
use WhiteMarket\Sdk\Export\Rows\RowSource;
use WhiteMarket\Sdk\Export\Rows\YajlStreamRows;
use WhiteMarket\Sdk\Tests\Support\RowSources;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Transport\BodyReader;

/**
 * The failures of the streaming products export (the row source and the mapper, as `streamProducts()` builds them)
 * carry no server bytes: not in the message, not in a chained exception, not in an argument of a frame of the trace
 * (`zend.exception_ignore_args=0` keeps the arguments, and an exception outlives the call in logs and error pages).
 */
#[CoversNothing]
final class StreamProductsTraceTest extends TestCase
{
    private const MARKER = 'SERVERBYTES';

    private const JSON = ['JsonMachineRows', 'BatchJsonRows (json_decode)', 'BatchJsonRows (simdjson)', 'YajlStreamRows'];

    /**
     * @return iterable<string, array{string, string, string}> decoder or 'csv', body, the path the failure names
     */
    public static function failures(): iterable
    {
        $good = self::jsonRow(['name_hash' => 'Synthetic ' . self::MARKER]);
        $badPrice = self::jsonRow(['name_hash' => 'Synthetic ' . self::MARKER, 'price' => self::MARKER]);
        $badKind = self::jsonRow(['name_hash' => 'Synthetic ' . self::MARKER, 'product_id' => [self::MARKER => 1]]);
        $long = '"' . self::filler() . '"';
        $json = [
            'an invalid element' => ['[' . $good . ',{"x":' . self::MARKER . '}]', 'products[1]'],
            'a structural error after a row with the marker' => ['[' . $good . ',1}', 'products[1]'],
            'a row too long' => ['[' . $good . ',' . $long . ']', 'products[1]'],
            'a row the mapper refuses (price)' => ['[' . $badPrice . ']', 'products[0].price'],
            'a row the mapper refuses (not a string)' => ['[' . $badKind . ']', 'products[0].product_id'],
            'a row that is not an object' => ['["' . self::MARKER . '"]', 'products[0]'],
        ];
        foreach (self::JSON as $decoder) {
            foreach ($json as $name => [$body, $path]) {
                yield $name . ' / ' . $decoder => [$decoder, $body, $path];
            }
        }
        $header = 'product_id,name_hash,price,currency,product_category';
        $csv = [
            'a field count mismatch' => [$header . "\na," . self::MARKER . "\n", 'products[0]'],
            'an unterminated quoted field' => [$header . "\n\"" . self::MARKER . ',1,2', 'products[0]'],
            'a record too long' => [$header . "\n" . self::filler(RowSource::MAX_ROW_BYTES + 1), 'products[0]'],
            'a bad price' => [$header . "\n00000000-0000-4000-8000-000000000001," . self::MARKER . ',' . self::MARKER . ",USD,GLOVES\n", 'products[0].price'],
            'a repeated column name' => ['a,a' . "\n" . self::MARKER, 'products'],
        ];
        foreach ($csv as $name => [$body, $path]) {
            yield $name . ' / csv' => ['csv', $body, $path];
        }
    }

    #[Test]
    #[DataProvider('failures')]
    public function aFailureHoldsNoServerBytesInTheMessageTheChainOrTheTrace(string $decoder, string $body, string $path): void
    {
        $rows = new RowIterator($this->source($decoder, RowSources::reader($body)), ExportMapper::productRow(...), 'products');

        // The iterator is not an argument of a frame (it would show its buffers), so it is read in the closure.
        $e = TraceArguments::caught(static function () use ($rows): void {
            $rows->rewind();
            while ($rows->valid()) {
                $rows->next();
            }
        });

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
        self::assertNull($e->getPrevious());
        self::assertStringNotContainsString(self::MARKER, $e->getMessage());
        self::assertStringNotContainsString(self::MARKER, TraceArguments::exportedSdkFrames($e));
        self::assertStringNotContainsString(self::MARKER, TraceArguments::printedSdkFrames($e));
    }

    #[Test]
    public function aGzipBodyOverTheRowLimitHoldsNoServerBytesInTheTrace(): void
    {
        $body = '[0,"' . self::filler() . '"]';
        $reader = RowSources::reader($this->gzip($body), ['Content-Encoding' => 'gzip']);
        $rows = new RowIterator($this->source('JsonMachineRows', $reader), static fn(mixed $row): mixed => $row, 'products');

        $e = TraceArguments::caught(static function () use ($rows): void {
            $rows->rewind();
            while ($rows->valid()) {
                $rows->next();
            }
        });

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[1]', $e->path);
        self::assertStringNotContainsString(self::MARKER, TraceArguments::exportedSdkFrames($e));
        self::assertStringNotContainsString(self::MARKER, TraceArguments::printedSdkFrames($e));
    }

    #[Test]
    public function theMapperHoldsNoServerBytesInTheTraceOfAJsonTextFailure(): void
    {
        $e = TraceArguments::caught(static fn(): mixed => ExportMapper::meta('{"file":' . self::MARKER . '}'));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertNull($e->getPrevious());
        self::assertStringNotContainsString(self::MARKER, $e->getMessage());
        self::assertStringNotContainsString(self::MARKER, TraceArguments::exportedSdkFrames($e));
        self::assertStringNotContainsString(self::MARKER, TraceArguments::printedSdkFrames($e));
    }

    #[Test]
    public function thePriceListMapperHoldsNoServerBytesInTheTraceOfARowFailure(): void
    {
        $e = TraceArguments::caught(static fn(): mixed => ExportMapper::priceRows('[{"market_hash_name":"' . self::MARKER . '"}]'));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringNotContainsString(self::MARKER, TraceArguments::exportedSdkFrames($e));
        self::assertStringNotContainsString(self::MARKER, TraceArguments::printedSdkFrames($e));
    }

    private function gzip(string $body): string
    {
        $gzip = gzencode($body, 6, \FORCE_GZIP);
        self::assertNotFalse($gzip);

        return $gzip;
    }

    private function source(string $decoder, BodyReader $reader): RowSource
    {
        switch ($decoder) {
            case 'csv':
                return new CsvRows($reader, 'products');
            case 'BatchJsonRows (simdjson)':
                if (!JsonStreamDecoder::Simdjson->isAvailable()) {
                    self::markTestSkipped('ext-simdjson is not loaded.');
                }

                return new BatchJsonRows($reader, 'products', true);
            case 'YajlStreamRows':
                if (!JsonStreamDecoder::YajlStream->isAvailable()) {
                    self::markTestSkipped('ext-yajl_stream is not loaded.');
                }

                return new YajlStreamRows($reader, 'products');
            case 'BatchJsonRows (json_decode)':
                return new BatchJsonRows($reader, 'products', false);
            default:
                return new JsonMachineRows($reader, 'products');
        }
    }

    /**
     * More text than a row may hold, with the marker all through it, so the bytes of the slice in which the limit is
     * hit hold the marker.
     */
    private static function filler(int $length = RowSource::MAX_ROW_BYTES + 3 * JsonArrayChunks::SLICE_BYTES): string
    {
        return substr(str_repeat(self::MARKER . str_repeat('a', 100), intdiv($length, 111) + 1), 0, $length);
    }

    /**
     * @param array<string, mixed> $override
     */
    private static function jsonRow(array $override = []): string
    {
        return json_encode($override + [
            'product_id' => '00000000-0000-4000-8000-000000000001',
            'product_category' => 'GLOVES',
            'product_class_id' => '18446744073709551615',
            'product_instance_id' => 188530139,
            'product_asset_id' => '9007199254740993',
            'name_hash' => 'Synthetic Gloves | Test (Field-Tested)',
            'price' => '7.060',
            'currency' => 'USD',
        ], \JSON_THROW_ON_ERROR);
    }
}
