<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export\Rows;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Export\Rows\CsvRows;
use WhiteMarket\Sdk\Export\Rows\GuardedRowSource;
use WhiteMarket\Sdk\Export\Rows\RowSource;
use WhiteMarket\Sdk\Tests\Support\PeakMemory;
use WhiteMarket\Sdk\Tests\Support\RowSources;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Tests\Support\TrackedStream;
use WhiteMarket\Sdk\Transport\BodyReader;

#[CoversClass(CsvRows::class)]
#[CoversClass(GuardedRowSource::class)]
final class CsvRowsTest extends TestCase
{
    /** BodyReader reads 8192 bytes per step; a body well over it arrives in several chunks. */
    private const CHUNK = 8192;

    /**
     * @return iterable<string, array{string, list<array<string, string|null>>}>
     */
    public static function csvBodies(): iterable
    {
        $abRows = [['a' => '1', 'b' => '2'], ['a' => '3', 'b' => '4']];
        yield 'LF line ends' => ["a,b\n1,2\n3,4\n", $abRows];
        yield 'CRLF line ends' => ["a,b\r\n1,2\r\n3,4\r\n", $abRows];
        yield 'the last record without a line end' => ["a,b\n1,2\n3,4", $abRows];
        yield 'the last record without a line end, CRLF before it' => ["a,b\r\n1,2\r\n3,4", $abRows];
        yield 'empty values are null' => ["a,b,c\n,2,\n1,,3\n", [['a' => null, 'b' => '2', 'c' => null], ['a' => '1', 'b' => null, 'c' => '3']]];
        yield 'a quoted empty value is null too' => ["a,b\n\"\",2\n", [['a' => null, 'b' => '2']]];
        yield 'a quoted field with a newline, a comma and doubled quotes' => [
            "a,b\n\"x\n,\"\"y\"\"\",z\n",
            [['a' => "x\n,\"y\"", 'b' => 'z']],
        ];
        yield 'a quoted CRLF inside a field is kept' => ["a,b\r\n\"x\r\ny\",z\r\n", [['a' => "x\r\ny", 'b' => 'z']]];
        yield 'empty lines are skipped' => ["\na,b\n\n1,2\n\r\n3,4\n\n", $abRows];
        yield 'a UTF-8 BOM before the header is stripped' => ["\xEF\xBB\xBFa,b\n1,2\n3,4\n", $abRows];
        yield 'a header only has no rows' => ["a,b\n", []];
        yield 'a header only, no line end' => ['a,b', []];
        yield 'a multibyte value is kept' => ["a,b\nСтикер,2\n", [['a' => 'Стикер', 'b' => '2']]];
        yield 'a single column' => ["a\n1\n2\n", [['a' => '1'], ['a' => '2']]];
    }

    /**
     * @param list<array<string, string|null>> $expected
     */
    #[Test]
    #[DataProvider('csvBodies')]
    public function mapsCsvRecordsToHeaderNamedRows(string $body, array $expected): void
    {
        self::assertSame($expected, RowSources::drain($this->csv(RowSources::reader($body))));
    }

    /**
     * @param list<array<string, string|null>> $expected
     */
    #[Test]
    #[DataProvider('csvBodies')]
    public function givesTheSameRowsWhateverTheChunkSize(string $body, array $expected): void
    {
        // From 3 bytes up: the BOM (3 bytes) is stripped only when the first chunk holds all of it.
        foreach ([3, 4, 7] as $size) {
            self::assertSame($expected, RowSources::drain($this->csv(RowSources::chunked($body, $size))), "chunks of {$size} bytes");
        }
    }

    #[Test]
    public function aBomInTheFirstChunkIsStrippedOnlyFromTheStartOfTheBody(): void
    {
        $rows = RowSources::drain($this->csv(RowSources::reader("a\n\xEF\xBB\xBF\n")));

        self::assertSame([['a' => "\xEF\xBB\xBF"]], $rows);
    }

    #[Test]
    public function aRowOfTheWrongWidthIsNamedByItsIndexAmongTheRowsNotTheLines(): void
    {
        [$rows, $failure] = RowSources::collect($this->csv(RowSources::reader("\na\n\n1\n2\n3\nx,y\n")));

        self::assertSame([['a' => '1'], ['a' => '2'], ['a' => '3']], $rows);
        self::assertInstanceOf(DecodeException::class, $failure);
        self::assertSame('products[3]', $failure->path);
    }

    #[Test]
    public function aQuotedFieldCutByAChunkBoundaryParses(): void
    {
        $head = "a,b\n" . str_repeat("1,2\n", 2040);
        $field = str_repeat('x', 40) . "\n" . str_repeat('y', 40);
        $body = $head . '"' . $field . "\",z\n3,4\n";
        self::assertGreaterThan(\strlen($head) + 20, self::CHUNK, 'the boundary is inside the quoted field');
        self::assertLessThan(\strlen($head) + 2 + 40, self::CHUNK);
        self::assertGreaterThan(self::CHUNK, \strlen($body));

        $rows = RowSources::drain($this->csv(RowSources::reader($body)));

        self::assertCount(2042, $rows);
        self::assertSame(['a' => $field, 'b' => 'z'], $rows[2040] ?? null);
        self::assertSame(['a' => '3', 'b' => '4'], $rows[2041] ?? null);
    }

    #[Test]
    public function aGzipBodyOfSeveralChunksParsesWhole(): void
    {
        $lines = [];
        for ($i = 0; $i < 600; ++$i) {
            $lines[] = hash('sha256', (string) $i) . ',"' . hash('md5', (string) $i) . "\nq\"\"\"";
        }
        $body = "a,b\n" . implode("\n", $lines) . "\n";
        $gzip = (string) gzencode($body);
        self::assertGreaterThan(1024 * 3, \strlen($gzip));

        $rows = RowSources::drain($this->csv(RowSources::reader($gzip, ['Content-Encoding' => 'gzip'])));

        self::assertCount(600, $rows);
        self::assertSame(['a' => hash('sha256', '599'), 'b' => hash('md5', '599') . "\nq\""], $rows[599] ?? null);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenCsv(): iterable
    {
        yield 'an empty body: no header' => ['', 'products'];
        yield 'only empty lines: no header' => ["\n\r\n\n", 'products'];
        yield 'a header with an empty column' => ["a,,b\n1,2,3\n", 'products'];
        yield 'a header with an empty last column' => ["a,b,\n1,2,3\n", 'products'];
        yield 'a header with a repeated column' => ["a,b,a\n1,2,3\n", 'products'];
        yield 'a first row with fewer fields' => ["a,b\n1\n", 'products[0]'];
        yield 'the second row with more fields' => ["a,b\n1,2\n3,4,5\n", 'products[1]'];
        yield 'the fourth row with fewer fields' => ["a,b\n1,2\n3,4\n5,6\n7\n8,9\n", 'products[3]'];
        yield 'the body ends inside a quoted field' => ["a,b\n1,\"2\n", 'products[0]'];
        yield 'the body ends inside a quoted field of the third row' => ["a,b\n1,2\n3,4\n5,\"6\n", 'products[2]'];
        yield 'the body ends right after an opening quote' => ["a\n\"", 'products[0]'];
        yield 'a header that is an unterminated quote' => ['"a,b', 'products'];
    }

    #[Test]
    #[DataProvider('brokenCsv')]
    public function aBrokenBodyFailsWithAPathAndNoServerBytes(string $body, string $path): void
    {
        $e = TraceArguments::caught(fn(): array => RowSources::drain($this->csv(RowSources::reader($body))));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
        self::assertStringNotContainsString('a,b', $e->getMessage());
    }

    #[Test]
    public function aBadRecordFailsAfterTheGoodRowsBeforeItWereYielded(): void
    {
        [$seen, $e] = RowSources::collect($this->csv(RowSources::reader("a,b\n1,2\n3,4\n5\n")));

        self::assertSame([['a' => '1', 'b' => '2'], ['a' => '3', 'b' => '4']], $seen);
        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[2]', $e->path);
    }

    #[Test]
    public function aRecordOfExactlyTheLimitWithoutALineEndPasses(): void
    {
        $value = str_repeat('x', RowSource::MAX_ROW_BYTES);

        $rows = RowSources::drain($this->csv(RowSources::reader("a\n" . $value)));

        self::assertSame([['a' => $value]], $rows);
    }

    #[Test]
    public function aRecordOfExactlyTheLimitWithALineEndPasses(): void
    {
        $value = str_repeat('x', RowSource::MAX_ROW_BYTES);

        $rows = RowSources::drain($this->csv(RowSources::reader("a\n" . $value . "\n1\n")));

        self::assertSame([['a' => $value], ['a' => '1']], $rows);
    }

    #[Test]
    public function aRecordOneByteOverTheLimitFails(): void
    {
        $body = "a\n" . str_repeat('x', RowSource::MAX_ROW_BYTES + 1);

        $e = TraceArguments::caught(fn(): array => RowSources::drain($this->csv(RowSources::reader($body))));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[0]', $e->path);
        self::assertStringContainsString('too long', $e->getMessage());
    }

    /**
     * Each failure of a record is named by the index of that record among the rows; the header is `products`.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function tooLongRecords(): iterable
    {
        $long = str_repeat('x', RowSource::MAX_ROW_BYTES + 1);
        $filler = 'a' . "\n" . "1\n2\n";
        yield 'a record with a line end after two rows' => [$filler . $long . "\n3\n", 'products[2]'];
        yield 'a record with a CRLF after two rows' => ["a\r\n1\r\n2\r\n" . $long . "\r\n", 'products[2]'];
        yield 'the last record without a line end after two rows' => [$filler . $long, 'products[2]'];
        yield 'an unterminated quoted field after two rows' => [$filler . '"' . $long, 'products[2]'];
        yield 'a record after empty lines does not count them' => ["a\n\n1\n\n" . $long . "\n", 'products[1]'];
        yield 'the header' => [$long . "\n1\n", 'products'];
        yield 'the header without a line end' => [$long, 'products'];
    }

    #[Test]
    #[DataProvider('tooLongRecords')]
    public function aTooLongRecordIsNamedByItsIndexAndTheHeaderByTheListPath(string $body, string $path): void
    {
        $e = TraceArguments::caught(fn(): array => RowSources::drain($this->csv(RowSources::reader($body))));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
        self::assertStringContainsString('too long', $e->getMessage());
    }

    /**
     * A gzip body of this size inflates in one reader step, so the whole record and its line end arrive together.
     */
    private function oneStepGzip(string $body): BodyReader
    {
        $gzip = (string) gzencode($body, 6, \FORCE_GZIP);
        $step = RowSources::reader($gzip, ['Content-Encoding' => 'gzip'])->next();
        self::assertSame(\strlen($body), \strlen((string) $step), 'the whole body arrives in one step');

        return RowSources::reader($gzip, ['Content-Encoding' => 'gzip']);
    }

    #[Test]
    public function aWholeRecordOverTheLimitInOneGzipStepIsRefusedAtThePath(): void
    {
        $body = "a\n" . str_repeat('x', RowSource::MAX_ROW_BYTES + 200_000) . "\ny\n";

        [$rows, $e] = RowSources::collect($this->csv($this->oneStepGzip($body)));

        self::assertSame([], $rows);
        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[0]', $e->path);
        self::assertStringContainsString('too long', $e->getMessage());
    }

    #[Test]
    public function aWholeRecordOfExactlyTheLimitInOneGzipStepPasses(): void
    {
        $value = str_repeat('x', RowSource::MAX_ROW_BYTES);

        $rows = RowSources::drain($this->csv($this->oneStepGzip("a\n" . $value . "\ny\n")));

        self::assertSame([['a' => $value], ['a' => 'y']], $rows);
    }

    #[Test]
    public function aWholeRecordOfCommasOverTheLimitInOneGzipStepIsRefusedBeforeItIsSplit(): void
    {
        $body = "a\n" . str_repeat(',', RowSource::MAX_ROW_BYTES + 1) . "\n";

        $e = TraceArguments::caught(fn(): array => RowSources::drain($this->csv($this->oneStepGzip($body))));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringContainsString('too long', $e->getMessage());
    }

    #[Test]
    public function anUnterminatedQuotedFieldOverTheLimitFailsBeforeTheEndOfTheBody(): void
    {
        $body = "a\n\"" . str_repeat('x', RowSource::MAX_ROW_BYTES * 2);
        $stream = TrackedStream::of($body);

        $e = TraceArguments::caught(fn(): array => RowSources::drain($this->csv(BodyReader::open(new Response(200, [], $stream)))));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringContainsString('too long', $e->getMessage());
        self::assertLessThan(\strlen($body), $stream->bytesRead(), 'the rest of the body was never read');
        self::assertTrue($stream->wasClosed());
    }

    #[Test]
    public function closesTheBodyWhenTheCallerStopsEarly(): void
    {
        $stream = TrackedStream::of("a,b\n" . str_repeat("1,2\n", 5000));
        $reader = BodyReader::open(new Response(200, [], $stream), rejectTrailingData: true);
        $source = $this->csv($reader);

        self::assertTrue($source->next());
        self::assertFalse($stream->wasClosed(), 'still open while the caller reads');
        $source->close();

        self::assertTrue($stream->wasClosed());
        self::assertNull($reader->next());
    }

    #[Test]
    public function closesTheBodyAtTheEndAndOnAFailure(): void
    {
        $good = TrackedStream::of("a\n1\n");
        RowSources::drain($this->csv(BodyReader::open(new Response(200, [], $good))));
        $bad = TrackedStream::of("a\n\"1");
        RowSources::collect($this->csv(BodyReader::open(new Response(200, [], $bad))));

        self::assertTrue($good->wasClosed());
        self::assertTrue($bad->wasClosed());
    }

    #[Test]
    public function twoWideRecordsInARowNeverLiveTogether(): void
    {
        $columns = 15_000;
        $header = implode(',', array_map(static fn(int $i): string => 'c' . $i, range(0, $columns - 1)));
        $record = implode(',', array_fill(0, $columns, 'v'));
        self::assertLessThanOrEqual(RowSource::MAX_ROW_BYTES, \strlen($header));
        $growth = static fn(string $body): int => PeakMemory::growthOfReading(static fn(): RowSource => new GuardedRowSource(new CsvRows(RowSources::reader($body), 'products')));
        $growth($header . "\n" . $record);

        $single = $growth($header . "\n" . $record . "\n");
        $double = $growth($header . "\n" . $record . "\n" . $record . "\n");

        self::assertGreaterThan(1024 * 1024, $single, 'the control record is big enough to measure');
        // Header and the fields of the record cost as much as a row, so a second live row shows as 1.35 times, not 2.
        self::assertLessThan(1.15 * $single, $double, 'peak growth of two records against one (bytes)');
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function plainOrGzip(): iterable
    {
        yield 'plain' => [false];
        yield 'gzip' => [true];
    }

    #[Test]
    #[DataProvider('plainOrGzip')]
    public function aRecordOfExactlyTheLimitWithACrlfLineEndPasses(bool $gzip): void
    {
        $value = str_repeat('x', RowSource::MAX_ROW_BYTES);

        $rows = RowSources::drain($this->csv($this->bodyReader("a\r\n" . $value . "\r\n1\r\n", $gzip)));

        self::assertSame([['a' => $value], ['a' => '1']], $rows);
    }

    #[Test]
    public function aRecordOfExactlyTheLimitWhoseCarriageReturnEndsAChunkPasses(): void
    {
        $value = str_repeat('x', RowSource::MAX_ROW_BYTES);
        $body = "a\r\n" . $value . "\r\n1\r\n";
        self::assertSame(0, (\strlen("a\r\n" . $value . "\r")) % 4, 'a 4 byte chunk ends right after the CR');

        $rows = RowSources::drain($this->csv(RowSources::chunked($body, 4)));

        self::assertSame([['a' => $value], ['a' => '1']], $rows);
    }

    #[Test]
    #[DataProvider('plainOrGzip')]
    public function theLastRecordOfExactlyTheLimitEndingInCrlfPasses(bool $gzip): void
    {
        $value = str_repeat('x', RowSource::MAX_ROW_BYTES);

        $rows = RowSources::drain($this->csv($this->bodyReader("a\r\n" . $value . "\r\n", $gzip)));

        self::assertSame([['a' => $value]], $rows);
    }

    #[Test]
    #[DataProvider('plainOrGzip')]
    public function aQuotedRecordWithLineFeedsOfExactlyTheLimitAndCrlfPasses(bool $gzip): void
    {
        $value = "\n" . str_repeat('x', RowSource::MAX_ROW_BYTES - 4) . "\n";
        $record = '"' . $value . '"';
        self::assertSame(RowSource::MAX_ROW_BYTES, \strlen($record));

        $rows = RowSources::drain($this->csv($this->bodyReader("a\r\n" . $record . "\r\n", $gzip)));

        self::assertSame([['a' => $value]], $rows);
    }

    #[Test]
    #[DataProvider('plainOrGzip')]
    public function aRecordOneByteOverTheLimitWithCrlfFails(bool $gzip): void
    {
        $body = "a\r\n" . str_repeat('x', RowSource::MAX_ROW_BYTES + 1) . "\r\n1\r\n";

        $e = TraceArguments::caught(fn(): array => RowSources::drain($this->csv($this->bodyReader($body, $gzip))));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringContainsString('too long', $e->getMessage());
    }

    /**
     * Our reader strips exactly one CR, the one of a CRLF; a second CR stays in the record. PHP's `str_getcsv()` then
     * drops the trailing CRs of an unquoted field itself, so the value is the same as without the second CR (the
     * limit of the PHP CSV parser, the record is still length-checked with it: see the cases above and below).
     */
    #[Test]
    #[DataProvider('plainOrGzip')]
    public function anUnquotedValueLosesItsTrailingCarriageReturnsToThePhpCsvParserNotToTheLineEnd(bool $gzip): void
    {
        $rows = RowSources::drain($this->csv($this->bodyReader("a\r\nx\r\r\ny\r\n", $gzip)));

        self::assertSame([['a' => 'x'], ['a' => 'y']], $rows);
    }

    #[Test]
    public function aLastRecordWithoutALineFeedIsReadWithItsTrailingCarriageReturn(): void
    {
        $rows = RowSources::drain($this->csv(RowSources::reader("a\n1\nx\r")));

        self::assertSame([['a' => '1'], ['a' => 'x']], $rows);
    }

    #[Test]
    #[DataProvider('plainOrGzip')]
    public function theLastRecordOfExactlyTheLimitFollowedByALoneCarriageReturnIsRefused(bool $gzip): void
    {
        $body = "a\n" . str_repeat('x', RowSource::MAX_ROW_BYTES) . "\r";

        $e = TraceArguments::caught(fn(): array => RowSources::drain($this->csv($this->bodyReader($body, $gzip))));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[0]', $e->path);
        self::assertStringContainsString('too long', $e->getMessage());
    }

    #[Test]
    public function aRecordOfExactlyTheLimitFollowedByTwoCarriageReturnsAndALineFeedIsRefused(): void
    {
        $body = "a\n" . str_repeat('x', RowSource::MAX_ROW_BYTES) . "\r\r\n";

        $e = TraceArguments::caught(fn(): array => RowSources::drain($this->csv(RowSources::reader($body))));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringContainsString('too long', $e->getMessage());
    }

    /**
     * @param positive-int $size
     */
    #[Test]
    #[DataProvider('chunkSizesInsideTheBom')]
    public function aBomCutByAChunkBoundaryIsStripped(int $size): void
    {
        $rows = RowSources::drain($this->csv(RowSources::chunked("\xEF\xBB\xBFa,b\n1,2\n", $size)));

        self::assertSame([['a' => '1', 'b' => '2']], $rows);
    }

    /**
     * @return iterable<string, array{positive-int}>
     */
    public static function chunkSizesInsideTheBom(): iterable
    {
        yield 'one byte chunks' => [1];
        yield 'two byte chunks' => [2];
    }

    private function bodyReader(string $body, bool $gzip): BodyReader
    {
        return $gzip
            ? RowSources::reader((string) gzencode($body, 6, \FORCE_GZIP), ['Content-Encoding' => 'gzip'])
            : RowSources::reader($body);
    }

    private function csv(BodyReader $reader): RowSource
    {
        return new GuardedRowSource(new CsvRows($reader, 'products'));
    }
}
