<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export\Rows;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Export\ExportMapper;
use WhiteMarket\Sdk\Export\JsonStreamDecoder;
use WhiteMarket\Sdk\Export\Rows\BatchJsonRows;
use WhiteMarket\Sdk\Export\Rows\BodyStreamWrapper;
use WhiteMarket\Sdk\Export\Rows\GuardedRowSource;
use WhiteMarket\Sdk\Export\Rows\JsonArrayChunks;
use WhiteMarket\Sdk\Export\Rows\JsonMachineRows;
use WhiteMarket\Sdk\Export\Rows\RowIterator;
use WhiteMarket\Sdk\Export\Rows\RowPath;
use WhiteMarket\Sdk\Export\Rows\RowSource;
use WhiteMarket\Sdk\Export\Rows\YajlStreamRows;
use WhiteMarket\Sdk\Tests\Support\PeakMemory;
use WhiteMarket\Sdk\Tests\Support\RowSources;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Tests\Support\TrackedStream;
use WhiteMarket\Sdk\Transport\BodyReader;

/**
 * One contract for every JSON row source (ADR 0006): the same cases run against json-machine, the batch decoder with
 * `json_decode()` and with `ext-simdjson`, and `ext-yajl_stream`. The extension variants are skipped where the
 * extension is not loaded (the Docker matrix images have both). Where the sources differ, the difference is the
 * expectation of the case, per source.
 */
#[CoversClass(JsonMachineRows::class)]
#[CoversClass(GuardedRowSource::class)]
#[CoversClass(RowPath::class)]
#[CoversClass(BatchJsonRows::class)]
#[CoversClass(YajlStreamRows::class)]
#[CoversClass(JsonArrayChunks::class)]
#[CoversClass(BodyStreamWrapper::class)]
#[CoversClass(RowIterator::class)]
final class JsonRowSourcesTest extends TestCase
{
    private const MACHINE = 'JsonMachineRows';

    private const BATCH = 'BatchJsonRows (json_decode)';

    private const SIMDJSON = 'BatchJsonRows (simdjson)';

    private const YAJL = 'YajlStreamRows';

    private const SOURCES = [self::MACHINE, self::BATCH, self::SIMDJSON, self::YAJL];

    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        foreach (self::SOURCES as $source) {
            yield $source => [$source];
        }
    }

    /**
     * @return iterable<string, array{string, string, list<mixed>}>
     */
    public static function goodBodies(): iterable
    {
        $cases = [
            'nested values and scalars are kept' => [
                '[{"a":1,"n":{"x":[1,2,{"y":null}]}}, "s", [3], null, true, 1.5, -0]',
                [['a' => 1, 'n' => ['x' => [1, 2, ['y' => null]]]], 's', [3], null, true, 1.5, 0],
            ],
            'an empty array' => ['[]', []],
            'an empty array with white space' => [" [ ]\n", []],
            'one row' => ['[{"a":1}]', [['a' => 1]]],
            'an integer at the signed 64-bit maximum stays an int' => ['[{"edge":9223372036854775807,"small":5}]', [['edge' => \PHP_INT_MAX, 'small' => 5]]],
            'a 19-digit integer stays an int' => ['[{"id":1234567890123456789}]', [['id' => 1234567890123456789]]],
            'a negative 19-digit integer stays an int' => ['[-1234567890123456789]', [-1234567890123456789]],
            'a long digit run inside a string is text' => ['["12345678901234567890123"]', ['12345678901234567890123']],
            'structural characters inside strings' => ['["],{x","\\"],","}",{"k]":"[{"}]', ['],{x', '"],', '}', ['k]' => '[{']]],
            'multibyte text and escapes' => ['["Стикер","\\u00e9\\n","日本"]', ['Стикер', "é\n", '日本']],
        ];
        foreach ($cases as $name => [$body, $rows]) {
            foreach (self::SOURCES as $source) {
                yield $name . ' / ' . $source => [$source, $body, $rows];
            }
        }
    }

    /**
     * @param list<mixed> $expected
     */
    #[Test]
    #[DataProvider('goodBodies')]
    public function yieldsTheElementsInOrder(string $source, string $body, array $expected): void
    {
        self::assertSame($expected, RowSources::drain($this->source($source, RowSources::reader($body))));
    }

    /**
     * @param list<mixed> $expected
     */
    #[Test]
    #[DataProvider('goodBodies')]
    public function yieldsTheSameElementsWhateverTheChunkSize(string $source, string $body, array $expected): void
    {
        foreach ([1, 3, 7] as $size) {
            self::assertSame($expected, RowSources::drain($this->source($source, RowSources::chunked($body, $size))), "chunks of {$size} bytes");
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function notAList(): iterable
    {
        $cases = [
            'a top-level object' => '{"a":1}',
            'a top-level object after white space' => "  \n\t{\"a\":1}",
            'a scalar' => '5',
            'a string' => '"x"',
            'an empty body' => '',
            'a whitespace-only body' => " \r\n\t ",
        ];
        foreach ($cases as $name => $body) {
            foreach (self::SOURCES as $source) {
                yield $name . ' / ' . $source => [$source, $body];
            }
        }
    }

    #[Test]
    #[DataProvider('notAList')]
    public function aBodyThatIsNotAnArrayFailsAtThePathOfTheFile(string $source, string $body): void
    {
        $rows = $this->source($source, RowSources::reader($body));

        $e = TraceArguments::caught(fn(): array => RowSources::drain($rows));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products', $e->path);
        self::assertStringContainsString('not a list of rows', $e->getMessage());
    }

    /**
     * Rows delivered before the failure: every source delivers every element before the bad one (SDK_SPEC 3.10 rule 9).
     *
     * @return iterable<string, array{string, string, string, int}>
     */
    public static function brokenBodies(): iterable
    {
        $cases = [
            'truncated inside the second row' => ['[{"a":1},{"a":', 'products[1]', 1],
            'a syntax error in the second row' => ['[{"a":1},{a}]', 'products[1]', 1],
            'a trailing comma' => ['[1,]', 'products[1]', 1],
            'a truncated first row' => ['[{"a"', 'products[0]', 0],
            'a bad literal in the second row' => ['[1,tru]', 'products[1]', 1],
        ];
        foreach ($cases as $name => [$body, $path, $good]) {
            foreach (self::SOURCES as $source) {
                yield $name . ' / ' . $source => [$source, $body, $path, $good];
            }
        }
    }

    #[Test]
    #[DataProvider('brokenBodies')]
    public function aBrokenBodyFailsAtTheRowItBreaksAt(string $source, string $body, string $path, int $delivered): void
    {
        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
        self::assertCount($delivered, $rows);
    }

    #[Test]
    #[DataProvider('sources')]
    public function aDecodeFailureCarriesNoBodyBytes(string $source): void
    {
        [, $e] = RowSources::collect($this->source($source, RowSources::reader('[{"a":1},{"zzmarker":x}]')));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringNotContainsString('zzmarker', $e->getMessage());
        $previous = $e->getPrevious();
        if (!$previous instanceof \Throwable) {
            return;
        }
        self::assertStringNotContainsString('zzmarker', $previous->getMessage());
    }

    #[Test]
    #[DataProvider('sources')]
    public function aDecodeFailureChainsNoLibraryException(string $source): void
    {
        [, $e] = RowSources::collect($this->source($source, RowSources::reader('[{"a":1},{a}]')));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertNotInstanceOf(\Throwable::class, $e->getPrevious(), 'the library exception quotes the body');
    }

    /**
     * A bad element is not chained and is not in a frame argument of the exception: the trace of an exception keeps
     * the arguments of the calls when `zend.exception_ignore_args` is off, and a `JsonException` in the chain holds
     * the `json_decode()` frame with the bytes.
     */
    #[Test]
    #[DataProvider('sources')]
    public function aDecodeFailureHoldsNoBodyBytesInTheChainOrTheTrace(string $source): void
    {
        $rows = $this->source($source, RowSources::reader('[{"a":1},{"x":SERVERBYTES}]'));

        // The source is not an argument of a frame (it would show its own buffers), so it is read in the closure.
        $e = TraceArguments::caught(static function () use ($rows): void {
            while ($rows->next()) {
            }
        });

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertNull($e->getPrevious());
        self::assertStringNotContainsString('SERVERBYTES', $e->getMessage());
        self::assertStringNotContainsString('SERVERBYTES', TraceArguments::exportedSdkFrames($e));
        self::assertStringNotContainsString('SERVERBYTES', TraceArguments::printedSdkFrames($e));
    }

    /**
     * @return iterable<string, array{string, string, float|string}>
     */
    public static function integersBeyondTheSignedRange(): iterable
    {
        // yajl_stream turns an integer beyond the 64-bit range into a float; the others keep a digit string.
        $cases = [
            '2^64' => ['18446744073709551616', '18446744073709551616'],
            '2^63, one over the maximum' => ['9223372036854775808', '9223372036854775808'],
            'a 25-digit integer' => ['1234567890123456789012345', '1234567890123456789012345'],
        ];
        foreach ($cases as $name => [$literal, $digits]) {
            foreach (self::SOURCES as $source) {
                yield $name . ' / ' . $source => [$source, $literal, $source === self::YAJL ? (float) $literal : $digits];
            }
        }
    }

    #[Test]
    #[DataProvider('integersBeyondTheSignedRange')]
    public function anIntegerBeyondTheSignedSixtyFourBitRange(string $source, string $literal, float|string $expected): void
    {
        $rows = RowSources::drain($this->source($source, RowSources::reader('[{"id":' . $literal . ',"small":5}]')));

        self::assertSame([['id' => $expected, 'small' => 5]], $rows);
    }

    #[Test]
    #[DataProvider('sources')]
    public function aBigIntegerInsideABatchWithOtherRowsStaysExact(string $source): void
    {
        $body = '[{"id":1},{"id":1234567890123456789},{"id":18446744073709551615},{"id":3}]';
        $big = $source === self::YAJL ? 1.8446744073709552E+19 : '18446744073709551615';

        $rows = RowSources::drain($this->source($source, RowSources::reader($body)));

        self::assertSame([['id' => 1], ['id' => 1234567890123456789], ['id' => $big], ['id' => 3]], $rows);
    }

    /**
     * Bytes after the closing bracket: json-machine and yajl_stream stop at the end of the array (the completeness of
     * the body is the reader's job: Content-Length, the gzip end), the batch decoder's scanner refuses them.
     *
     * @return iterable<string, array{string, bool}>
     */
    public static function trailingJunk(): iterable
    {
        foreach (self::SOURCES as $source) {
            yield $source => [$source, $source === self::MACHINE || $source === self::YAJL];
        }
    }

    #[Test]
    #[DataProvider('trailingJunk')]
    public function bytesAfterTheArray(string $source, bool $ignored): void
    {
        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader('[1]x')));

        if ($ignored) {
            self::assertSame([1], $rows);
            self::assertNotInstanceOf(\Throwable::class, $e);

            return;
        }
        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products', $e->path);
        self::assertStringContainsString('data after the end of the list', $e->getMessage());
    }

    #[Test]
    #[DataProvider('sources')]
    public function whiteSpaceAfterTheArrayIsFine(string $source): void
    {
        $rows = RowSources::drain($this->source($source, RowSources::reader("[1,2] \r\n\t\n")));

        self::assertSame([1, 2], $rows);
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>}>
     */
    public static function failingBodies(): iterable
    {
        $cases = [
            'Content-Length longer than the body' => ['[{"a":1},{"a":2}]', ['Content-Length' => '1000']],
            'Content-Length shorter than the body' => ['[{"a":1},{"a":2}]', ['Content-Length' => '5']],
            'invalid gzip' => ['not gzip at all', ['Content-Encoding' => 'gzip']],
            'truncated gzip' => [substr((string) gzencode('[{"a":1},{"a":2}]'), 0, -6), ['Content-Encoding' => 'gzip']],
        ];
        foreach ($cases as $name => [$body, $headers]) {
            foreach (self::SOURCES as $source) {
                yield $name . ' / ' . $source => [$source, $body, $headers];
            }
        }
    }

    /**
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('failingBodies')]
    public function aFailureOfTheBodyIsTheTransportExceptionUnchanged(string $source, string $body, array $headers): void
    {
        [, $e] = RowSources::collect($this->source($source, RowSources::reader($body, $headers)));

        self::assertInstanceOf(TransportException::class, $e);
    }

    #[Test]
    #[DataProvider('sources')]
    public function aFailureOfTheBodyAfterGoodRowsIsATransportErrorNotADecodeError(string $source): void
    {
        $body = '[{"a":1},{"a":2}]';

        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body, ['Content-Length' => (string) (\strlen($body) + 100)])));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertLessThanOrEqual(2, \count($rows));
    }

    /**
     * @return iterable<string, array{string, string, array<string, string>, string}>
     */
    public static function bodiesCutMidList(): iterable
    {
        $cut = '[' . implode(',', array_map(static fn(int $i): string => '{"i":' . $i . '}', range(0, 4))) . ',{"i":5';
        $cases = [
            'Content-Length longer than the body' => [$cut, ['Content-Length' => '1000'], 'shorter than Content-Length'],
            'truncated gzip' => [substr((string) gzencode($cut), 0, -6), ['Content-Encoding' => 'gzip'], 'not valid gzip'],
        ];
        foreach ($cases as $name => [$body, $headers, $message]) {
            foreach (self::SOURCES as $source) {
                yield $name . ' / ' . $source => [$source, $body, $headers, $message];
            }
        }
    }

    /**
     * The rows before the cut arrive first (SDK_SPEC 3.10 rule 9), then the reader's own exception: not a DecodeException.
     *
     * @param array<string, string> $headers
     */
    #[Test]
    #[DataProvider('bodiesCutMidList')]
    public function aBodyCutMidListDeliversTheRowsBeforeTheCutThenTheTransportException(string $source, string $body, array $headers, string $message): void
    {
        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body, $headers)));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertStringContainsString($message, $e->getMessage());
        self::assertSame([['i' => 0], ['i' => 1], ['i' => 2], ['i' => 3], ['i' => 4]], $rows);
    }

    #[Test]
    #[DataProvider('sources')]
    public function aGzipBodyOfSeveralChunksParsesWhole(string $source): void
    {
        [$body, $expected] = $this->bodyOfRows(400);
        $gzip = (string) gzencode($body);
        self::assertGreaterThan(1024 * 3, \strlen($gzip), 'several compressed slices');

        $rows = RowSources::drain($this->source($source, RowSources::reader($gzip, ['Content-Encoding' => 'gzip'])));

        self::assertSame($expected, $rows);
    }

    /**
     * More than two batches of 1000: rows with array and object punctuation inside strings, 19-digit integers in
     * the middle (a batch that simdjson is not trusted with) and long digit runs inside strings.
     */
    #[Test]
    #[DataProvider('sources')]
    public function aBodyOfSeveralBatchesGivesTheSameRowsInEverySource(string $source): void
    {
        [$body, $expected] = $this->bigBody(2600);
        self::assertGreaterThan(8192 * 3, \strlen($body));

        self::assertSame($expected, RowSources::drain($this->source($source, RowSources::reader($body))));
    }

    #[Test]
    #[DataProvider('sources')]
    public function aBodyOfSeveralBatchesGivesTheSameRowsInSmallChunks(string $source): void
    {
        [$body, $expected] = $this->bigBody(2100);

        self::assertSame($expected, RowSources::drain($this->source($source, RowSources::chunked($body, 997))));
    }

    #[Test]
    #[DataProvider('sources')]
    public function rowsLargerThanTheByteLimitOfABatchGiveTheSameRows(string $source): void
    {
        // 12 rows of 70 KB: a batch ends by its bytes (128 KiB), long before 1000 rows.
        $rows = [];
        for ($i = 0; $i < 12; ++$i) {
            $rows[] = ['i' => $i, 'text' => str_repeat(\chr(97 + $i), 70_000)];
        }
        $body = json_encode($rows, \JSON_THROW_ON_ERROR);

        self::assertSame($rows, RowSources::drain($this->source($source, RowSources::reader($body))));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function oneBadRowInALongList(): iterable
    {
        foreach (self::SOURCES as $source) {
            yield 'row 1500 of 2000 / ' . $source => [$source, 1500];
        }
    }

    #[Test]
    #[DataProvider('oneBadRowInALongList')]
    public function oneInvalidElementInALongListIsNamedByItsIndex(string $source, int $delivered): void
    {
        $elements = [];
        for ($i = 0; $i < 2000; ++$i) {
            $elements[] = $i === 1500 ? '{a}' : '{"i":' . $i . '}';
        }
        $body = '[' . implode(',', $elements) . ']';

        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[1500]', $e->path);
        self::assertCount($delivered, $rows);
        self::assertSame(['i' => 0], $rows[0] ?? null);
    }

    #[Test]
    #[DataProvider('sources')]
    public function aRowJustUnderTheRowLimitDecodes(string $source): void
    {
        $text = str_repeat('a', RowSource::MAX_ROW_BYTES - 1024);
        $body = '[{"i":0},{"text":"' . $text . '"},{"i":2}]';

        $rows = RowSources::drain($this->source($source, RowSources::reader($body)));

        self::assertSame([['i' => 0], ['text' => $text], ['i' => 2]], $rows);
    }

    #[Test]
    #[DataProvider('sources')]
    public function manyShortRowsFarBeyondTheRowLimitInTotalDecode(string $source): void
    {
        $count = 400_000;
        $body = '[' . implode(',', array_fill(0, $count, '{"a":1}')) . ']';
        self::assertGreaterThan(RowSource::MAX_ROW_BYTES + BodyReader::MAX_STEP_BYTES, \strlen($body));

        $rows = $this->source($source, RowSources::reader($body));
        $seen = 0;
        while ($rows->next()) {
            $seen += $rows->row() === ['a' => 1] ? 1 : 0;
        }

        self::assertSame($count, $seen);
    }

    /**
     * The second row is a string that never ends, inside a gzip body that inflates 1000:1: a source that does not cap
     * one row would collect it until the memory limit. Every source refuses it at the row, and the memory stays
     * within a few MiB of what it was before.
     */
    #[Test]
    #[DataProvider('sources')]
    public function aRowOverTheRowLimitIsRefusedAtItsIndexWithBoundedMemory(string $source): void
    {
        $gzip = $this->gzipWithoutEnd('[{"i":0},{"text":"', 'a', 64);
        $reader = RowSources::reader($gzip, ['Content-Encoding' => 'gzip']);
        $rows = $this->source($source, $reader);
        memory_reset_peak_usage();
        $before = memory_get_usage();

        [$got, $e] = RowSources::collect($rows);
        $growth = memory_get_peak_usage() - $before;

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[1]', $e->path);
        self::assertStringContainsString('a list element is too long', $e->getMessage());
        self::assertCount(1, $got);
        self::assertLessThan(16 * 1024 * 1024, $growth, 'memory growth in bytes');
    }

    /**
     * One gzip step (up to MAX_STEP_BYTES) holds the end of the small row 0 and the start of row 1; the parser reads
     * ahead by slices, so the unparsed rest of that step may not be free room for row 1. The slice in which row 0
     * ends is not counted for row 1, so a row is refused for certain from MAX_ROW_BYTES + 3 slices on (the counted
     * limit is MAX_ROW_BYTES + 2 slices).
     */
    #[Test]
    #[DataProvider('sources')]
    public function aRowThatStartsInsideAStepAndOverrunsTheLimitIsRefusedWhateverTheStepSize(string $source): void
    {
        $text = str_repeat('a', RowSource::MAX_ROW_BYTES + 3 * JsonArrayChunks::SLICE_BYTES);
        $body = '[0,"' . $text . '"]';
        $reader = RowSources::reader($this->gzip($body), ['Content-Encoding' => 'gzip']);

        [$rows, $e] = RowSources::collect($this->source($source, $reader));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[1]', $e->path);
        self::assertStringContainsString('a list element is too long', $e->getMessage());
        self::assertSame([0], $rows);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function exactRowSizes(): iterable
    {
        foreach (self::SOURCES as $source) {
            yield 'plain / ' . $source => [$source, false];
            yield 'gzip, one big step / ' . $source => [$source, true];
        }
    }

    #[Test]
    #[DataProvider('exactRowSizes')]
    public function aRowOfExactlyTheRowLimitDecodesEvenWhenAStepHoldsItWhole(string $source, bool $gzip): void
    {
        $text = str_repeat('a', RowSource::MAX_ROW_BYTES - 2);
        $body = '[0,"' . $text . '",2]';
        $reader = $gzip
            ? RowSources::reader($this->gzip($body), ['Content-Encoding' => 'gzip'])
            : RowSources::reader($body);

        $rows = RowSources::drain($this->source($source, $reader));

        self::assertSame([0, $text, 2], $rows);
    }

    #[Test]
    #[DataProvider('sources')]
    public function aRowOfManyTinyNestedArraysJustUnderTheLimitCostsBoundedMemory(string $source): void
    {
        $count = intdiv(RowSource::MAX_ROW_BYTES - 2, 4) - 1;
        $row = '[' . str_repeat('[0],', $count) . '[0]]';
        self::assertLessThanOrEqual(RowSource::MAX_ROW_BYTES, \strlen($row));
        self::assertGreaterThan(RowSource::MAX_ROW_BYTES - 16, \strlen($row));
        $reader = RowSources::reader($this->gzip('[1,' . $row . ',3]'), ['Content-Encoding' => 'gzip']);
        $rows = $this->source($source, $reader);
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $got = RowSources::drain($rows);
        $growth = memory_get_peak_usage() - $before;

        self::assertCount(3, $got);
        self::assertLessThan(32 * 1024 * 1024, $growth, 'memory growth in bytes');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function batchSources(): iterable
    {
        yield self::BATCH => [self::BATCH];
        yield self::SIMDJSON => [self::SIMDJSON];
    }

    /**
     * 120 rows of 1020 bytes (just under a batch's byte limit) and then a row of 125 KB: a batch that still takes the
     * last row would decode about twice the limit at once.
     */
    #[Test]
    #[DataProvider('batchSources')]
    public function aBatchNeverTakesARowThatPushesItFarOverTheByteLimit(string $source): void
    {
        $small = str_repeat('[', 510) . str_repeat(']', 510);
        $tower = str_repeat('[', 500) . str_repeat(']', 500);
        $big = '[' . implode(',', array_fill(0, 125, $tower)) . ']';
        $body = '[' . implode(',', array_fill(0, 120, $small)) . ',' . $big . ']';
        self::assertGreaterThan(BatchJsonRows::BATCH_BYTES, 120 * \strlen($small) + \strlen($big) - 100_000);
        $rows = $this->source($source, RowSources::reader($body));
        memory_reset_peak_usage();
        $before = memory_get_usage();

        $seen = 0;
        $widths = [];
        while ($rows->next()) { // the rows are not kept: only what the source holds counts
            $row = $rows->row();
            $widths[] = \is_array($row) ? \count($row) : -1;
            ++$seen;
        }
        $growth = memory_get_peak_usage() - $before;

        self::assertSame(121, $seen);
        self::assertSame(125, $widths[120] ?? null);
        self::assertSame(1, $widths[119]);
        self::assertLessThan(36 * 1024 * 1024, $growth, 'memory growth in bytes');
    }

    /**
     * Element sizes (bytes of JSON text) around the byte limit of a batch, and the index of an invalid element or null.
     *
     * @return iterable<string, array{string, list<int>, int|null}>
     */
    public static function rowsAroundTheBatchBoundary(): iterable
    {
        $limit = BatchJsonRows::BATCH_BYTES;
        $cases = [
            'a batch of exactly the limit' => [[40_000, 40_000, $limit - 80_000, 10, 20], null],
            'the third row one byte over the limit starts the next batch' => [[40_000, 40_000, $limit - 79_999, 10, 20], null],
            'the first row alone fills the limit' => [[$limit, 10, $limit, 20], null],
            'a row at the limit after small ones is carried over' => [[10, 20, $limit, 30], null],
            'an invalid row right after an exact batch' => [[40_000, 40_000, $limit - 80_000, 10, 20], 3],
            'an invalid row that was carried over' => [[40_000, 40_000, $limit - 79_999, 10, 20], 2],
            'an invalid row right after the carried one' => [[40_000, 40_000, $limit - 79_999, 10, 20], 3],
            'an invalid row two batches later' => [[$limit, 40_000, $limit - 39_999, 30, 40], 3],
        ];
        foreach ($cases as $name => [$sizes, $bad]) {
            foreach (self::SOURCES as $source) {
                yield $name . ' / ' . $source => [$source, $sizes, $bad];
            }
        }
    }

    /**
     * @param list<int> $sizes
     */
    #[Test]
    #[DataProvider('rowsAroundTheBatchBoundary')]
    public function rowsAroundTheBatchBoundaryKeepTheirOrderAndIndexes(string $source, array $sizes, ?int $bad): void
    {
        $elements = [];
        $expected = [];
        foreach ($sizes as $i => $size) {
            $text = str_repeat(\chr(97 + $i), $size - 2);
            $expected[] = $text;
            $elements[] = $i === $bad ? '{' . $text . '}' : '"' . $text . '"';
        }
        $body = '[' . implode(',', $elements) . ']';

        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body)));

        if ($bad === null) {
            self::assertNull($e);
            self::assertSame($expected, $rows);

            return;
        }
        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[' . $bad . ']', $e->path);
        self::assertSame(\array_slice($expected, 0, $bad), $rows);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function towerRows(): iterable
    {
        foreach (self::SOURCES as $source) {
            yield 'nested arrays / ' . $source => [$source, false];
            yield 'nested objects / ' . $source => [$source, true];
        }
    }

    /**
     * A row of the largest size the sources accept, made of 500 level deep towers: a decoded row costs far more than
     * its text, so a second one alive at the same time doubles the peak.
     */
    private function towerRow(bool $objects): string
    {
        $tower = $objects
            ? str_repeat('{"":', 500) . '0' . str_repeat('}', 500)
            : str_repeat('[', 500) . str_repeat(']', 500);

        return '[' . implode(',', array_fill(0, intdiv(RowSource::MAX_ROW_BYTES - 2, \strlen($tower) + 1), $tower)) . ']';
    }

    #[Test]
    #[DataProvider('towerRows')]
    public function twoWorstCaseRowsInARowNeverLiveTogether(string $source, bool $objects): void
    {
        $row = $this->towerRow($objects);
        self::assertLessThanOrEqual(RowSource::MAX_ROW_BYTES, \strlen($row));
        $growth = fn(string $body): int => PeakMemory::growthOfReading(fn(): RowSource => $this->source($source, RowSources::reader($body)));
        $growth('[' . $row . ']'); // warms the autoloader and the allocator

        $single = $growth('[' . $row . ']');
        $double = $growth('[' . $row . ',' . $row . ']');

        self::assertGreaterThan(1024 * 1024, $single, 'the control row is big enough to measure');
        self::assertLessThan(1.5 * $single, $double, 'peak growth of two rows against one (bytes)');
    }

    #[Test]
    #[DataProvider('towerRows')]
    public function twoWorstCaseMappedRowsNeverLiveTogether(string $source, bool $objects): void
    {
        $tower = $objects
            ? str_repeat('{"":', 500) . '0' . str_repeat('}', 500)
            : str_repeat('[', 500) . str_repeat(']', 500);
        $product = json_encode($this->product(), \JSON_THROW_ON_ERROR);
        $filler = intdiv(RowSource::MAX_ROW_BYTES - \strlen($product) - 16, \strlen($tower) + 1);
        $row = substr($product, 0, -1) . ',"z":[' . implode(',', array_fill(0, $filler, $tower)) . ']}';
        self::assertLessThanOrEqual(RowSource::MAX_ROW_BYTES, \strlen($row));
        $this->growthOfMappedRows($source, '[' . $row . ']');

        $single = $this->growthOfMappedRows($source, '[' . $row . ']');
        $double = $this->growthOfMappedRows($source, '[' . $row . ',' . $row . ']');

        self::assertGreaterThan(1024 * 1024, $single, 'the control row is big enough to measure');
        self::assertLessThan(1.5 * $single, $double, 'peak growth of two rows against one (bytes)');
    }

    /**
     * json-machine decodes a row alone with depth 512: 511 nested levels pass, 512 fail. ext-yajl_stream has no depth
     * limit (a known deviation, docs/DEVIATIONS.md), so it is not part of this contract.
     *
     * @return iterable<string, array{string, string}>
     */
    public static function nestingShapes(): iterable
    {
        foreach ([self::MACHINE, self::BATCH, self::SIMDJSON] as $source) {
            foreach (['arrays' => 'arrays', 'objects' => 'objects', 'arrays around a scalar' => 'scalar'] as $label => $shape) {
                yield $label . ' / ' . $source => [$source, $shape];
            }
        }
    }

    private function nested(string $shape, int $levels): string
    {
        return match ($shape) {
            'arrays' => str_repeat('[', $levels) . str_repeat(']', $levels),
            'objects' => str_repeat('{"":', $levels - 1) . '{}' . str_repeat('}', $levels - 1),
            default => str_repeat('[', $levels) . '0' . str_repeat(']', $levels),
        };
    }

    #[Test]
    #[DataProvider('nestingShapes')]
    public function aRowNestedAsDeepAsTheStreamingParserAcceptsDecodes(string $source, string $shape): void
    {
        $body = '[{"a":1},' . $this->nested($shape, 511) . ',{"a":2}]';

        $rows = RowSources::drain($this->source($source, RowSources::reader($body)));

        self::assertCount(3, $rows);
        self::assertSame(['a' => 2], $rows[2]);
    }

    #[Test]
    #[DataProvider('nestingShapes')]
    public function aRowNestedOneLevelDeeperIsRefusedAtItsIndexAfterTheRowsBeforeIt(string $source, string $shape): void
    {
        $body = '[{"a":1},' . $this->nested($shape, 512) . ',{"a":2}]';

        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[1]', $e->path);
        self::assertSame([['a' => 1]], $rows);
    }

    /**
     * `foreach` keeps the previous product alive while the next row is decoded, so the worst case is two rows. The
     * bulk of the row is under an unknown key, so it lands in ProductRow::extra and survives the mapping.
     */
    #[Test]
    #[DataProvider('towerRows')]
    public function foreachOverWorstCaseProductsStaysWithinTheMemoryBudget(string $source, bool $objects): void
    {
        $tower = $objects
            ? str_repeat('{"":', 500) . '0' . str_repeat('}', 500)
            : str_repeat('[', 500) . str_repeat(']', 500);
        $product = json_encode($this->product(), \JSON_THROW_ON_ERROR);
        $filler = intdiv(RowSource::MAX_ROW_BYTES - \strlen($product) - 16, \strlen($tower) + 1);
        $row = substr($product, 0, -1) . ',"z":[' . implode(',', array_fill(0, $filler, $tower)) . ']}';
        self::assertLessThanOrEqual(RowSource::MAX_ROW_BYTES, \strlen($row));
        self::assertGreaterThan(RowSource::MAX_ROW_BYTES - 2048, \strlen($row));
        $body = '[' . implode(',', array_fill(0, 4, $row)) . ']';
        $run = (fn(): int => PeakMemory::growth(function () use ($source, $body): void {
            $products = new RowIterator($this->decoder($source, RowSources::reader($body)), ExportMapper::productRow(...), 'products');
            $seen = 0;
            foreach ($products as $product) { // $product stays alive while the next row is decoded
                self::assertNotSame([], $product->extra);
                ++$seen;
            }
            self::assertSame(4, $seen);
        }));
        $run(); // warms the autoloader and the allocator

        self::assertLessThan(32 * 1024 * 1024, $run(), 'peak memory growth in bytes');
    }

    /**
     * @throws \LogicException
     * @throws TransportException
     * @throws DecodeException
     */
    private function growthOfMappedRows(string $source, string $body): int
    {
        return PeakMemory::growth(function () use ($source, $body): void {
            $rows = new RowIterator($this->decoder($source, RowSources::reader($body)), ExportMapper::productRow(...), 'products');
            for ($rows->rewind(); $rows->valid(); $rows->next()) {
                self::assertNotNull($rows->current()); // a temporary: no row is kept
            }
        });
    }

    /**
     * The body of an array whose elements are `{"i":N}`, with the elements at the given indexes replaced.
     *
     * @param array<int, string> $replaced
     */
    private function rowsWith(int $count, array $replaced): string
    {
        $elements = [];
        for ($i = 0; $i < $count; ++$i) {
            $elements[] = $replaced[$i] ?? '{"i":' . $i . '}';
        }

        return '[' . implode(',', $elements) . ']';
    }

    /**
     * Where the bad element sits relative to the batch of 1000, and the elements before it that must all arrive.
     *
     * @return iterable<string, array{string, int, string}>
     */
    public static function badElementsAmongManyRows(): iterable
    {
        $bad = [
            'the last element of a batch' => [999, '{a}'],
            'the first element of the next batch' => [1000, '{a}'],
            'the very first element' => [0, '{a}'],
            'an element cut short' => [10, '{"i":'],
        ];
        foreach ($bad as $name => [$index, $text]) {
            foreach (self::SOURCES as $source) {
                yield $name . ' / ' . $source => [$source, $index, $text];
            }
        }
    }

    #[Test]
    #[DataProvider('badElementsAmongManyRows')]
    public function everyRowBeforeABadElementIsDeliveredWhateverTheBatches(string $source, int $index, string $text): void
    {
        $body = $this->rowsWith(1500, [$index => $text]);

        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[' . $index . ']', $e->path);
        self::assertCount($index, $rows);
        self::assertSame($index > 0 ? ['i' => $index - 1] : null, $rows[$index - 1] ?? null);
    }

    #[Test]
    #[DataProvider('sources')]
    public function aTooLongElementAmongGoodRowsFailsAfterTheRowsBeforeIt(string $source): void
    {
        $long = '"' . str_repeat('a', RowSource::MAX_ROW_BYTES + 4 * JsonArrayChunks::SLICE_BYTES) . '"';
        $body = $this->rowsWith(20, [10 => $long]);

        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[10]', $e->path);
        self::assertCount(10, $rows);
        self::assertSame(['i' => 9], $rows[9]);
    }

    #[Test]
    #[DataProvider('sources')]
    public function bigRowsBeforeAnInvalidOneAreDelivered(string $source): void
    {
        $big = '"' . str_repeat('b', 100_000) . '"';
        $body = '[' . $big . ',' . $big . ',{a}]';

        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[2]', $e->path);
        self::assertSame([str_repeat('b', 100_000), str_repeat('b', 100_000)], $rows);
    }

    #[Test]
    #[DataProvider('sources')]
    public function aBigIntegerBeforeABadElementKeepsItsDigits(string $source): void
    {
        $body = '[{"id":9223372036854775808},{"id":1},{a}]';

        [$rows, $e] = RowSources::collect($this->source($source, RowSources::reader($body)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[2]', $e->path);
        self::assertCount(2, $rows);
        // yajl turns an integer beyond the 64-bit range into a float; the others keep the digits
        $big = $source === self::YAJL ? 9.2233720368547758E+18 : '9223372036854775808';
        self::assertSame([['id' => $big], ['id' => 1]], $rows);
    }

    /**
     * @return array<string, mixed>
     */
    private function product(): array
    {
        return [
            'product_id' => '00000000-0000-4000-8000-000000000001',
            'product_category' => 'GLOVES',
            'product_class_id' => '18446744073709551615',
            'product_instance_id' => 188530139,
            'product_asset_id' => '9007199254740993',
            'name_hash' => 'Synthetic Gloves | Test (Field-Tested)',
            'price' => '7.060',
            'currency' => 'USD',
            'inspectLink' => 'steam://rungame/730/1/+csgo_econ_action_preview%20SYNTHETIC1',
        ];
    }

    private function gzipWithoutEnd(string $prefix, string $filler, int $megabytes): string
    {
        $context = deflate_init(\ZLIB_ENCODING_GZIP);
        self::assertNotFalse($context);
        $gzip = deflate_add($context, $prefix, \ZLIB_NO_FLUSH);
        $block = str_repeat($filler, 1024 * 1024);
        for ($i = 0; $i < $megabytes; ++$i) {
            $gzip .= deflate_add($context, $block, \ZLIB_NO_FLUSH);
        }

        return $gzip . deflate_add($context, '', \ZLIB_FINISH);
    }

    #[Test]
    #[DataProvider('sources')]
    public function closingEarlyClosesTheBodyAndEndsTheRows(string $source): void
    {
        // Over two batches of 1000 rows, so the batch decoder has not reached the end of the body either.
        [$body] = $this->bodyOfRows(2500);
        $stream = TrackedStream::of($body);
        $reader = BodyReader::open(new Response(200, [], $stream), rejectTrailingData: true);
        $rows = $this->source($source, $reader);

        self::assertTrue($rows->next());
        self::assertFalse($stream->wasClosed(), 'still open while the caller reads');
        $rows->close();

        self::assertTrue($stream->wasClosed());
        self::assertNull($reader->next());
    }

    #[Test]
    #[DataProvider('sources')]
    public function closesTheBodyAtTheEndAndOnAFailure(string $source): void
    {
        $good = TrackedStream::of('[1,2]');
        RowSources::drain($this->source($source, BodyReader::open(new Response(200, [], $good), rejectTrailingData: true)));
        $bad = TrackedStream::of('[1,');
        [, $e] = RowSources::collect($this->source($source, BodyReader::open(new Response(200, [], $bad), rejectTrailingData: true)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertTrue($good->wasClosed());
        self::assertTrue($bad->wasClosed());
    }

    #[Test]
    #[DataProvider('sources')]
    public function aSourceThatNeverReadDoesNotTouchTheBodyUntilAsked(string $source): void
    {
        $stream = TrackedStream::of('[1,2,3]');

        $this->source($source, BodyReader::open(new Response(200, [], $stream), rejectTrailingData: true));

        self::assertSame(0, $stream->bytesRead());
    }

    private function gzip(string $body): string
    {
        $gzip = gzencode($body, 6, \FORCE_GZIP);
        self::assertNotFalse($gzip);

        return $gzip;
    }

    private function source(string $name, BodyReader $reader): RowSource
    {
        return new GuardedRowSource($this->decoder($name, $reader));
    }

    private function decoder(string $name, BodyReader $reader): RowSource
    {
        switch ($name) {
            case self::SIMDJSON:
                if (!JsonStreamDecoder::Simdjson->isAvailable()) {
                    self::markTestSkipped('ext-simdjson is not loaded.');
                }

                return new BatchJsonRows($reader, 'products', true);
            case self::YAJL:
                if (!JsonStreamDecoder::YajlStream->isAvailable()) {
                    self::markTestSkipped('ext-yajl_stream is not loaded.');
                }

                return new YajlStreamRows($reader, 'products');
            case self::BATCH:
                return new BatchJsonRows($reader, 'products', false);
            default:
                return new JsonMachineRows($reader, 'products');
        }
    }

    /**
     * @return array{string, list<array{id: string, name: string, nested: array{k: list<int>}}>}
     */
    private function bodyOfRows(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $rows[] = ['id' => (string) $i, 'name' => hash('sha256', (string) $i), 'nested' => ['k' => [$i, $i + 1]]];
        }

        return [json_encode($rows, \JSON_THROW_ON_ERROR), $rows];
    }

    /**
     * @return array{string, list<array<string, mixed>>}
     */
    private function bigBody(int $count): array
    {
        $rows = [];
        for ($i = 0; $i < $count; ++$i) {
            $row = ['i' => $i, 'text' => 'x"],{x' . $i . '\\', 'obj' => ['k]' => ['}' . $i, null, 1.5]], 'digits' => '98765432109876543210'];
            if ($i >= 1500 && $i < 1600) {
                $row['asset'] = 1234567890123456789 + $i;
            }
            $rows[] = $row;
        }

        return [json_encode($rows, \JSON_THROW_ON_ERROR), $rows];
    }
}
