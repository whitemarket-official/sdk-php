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
use WhiteMarket\Sdk\Export\Rows\JsonArrayScanner;
use WhiteMarket\Sdk\Export\Rows\RowSource;
use WhiteMarket\Sdk\Tests\Support\RowSources;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Tests\Support\TrackedStream;
use WhiteMarket\Sdk\Transport\BodyReader;

#[CoversClass(JsonArrayScanner::class)]
final class JsonArrayScannerTest extends TestCase
{
    /** BodyReader reads 8192 bytes per step. */
    private const CHUNK = 8192;

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function documents(): iterable
    {
        yield 'an empty array' => ['[]', []];
        yield 'an empty array with white space' => [" \r\n[ \t]\n", []];
        yield 'scalars, trimmed' => ['[ 1 , "a" ,true,null , -2.5e3 ]', ['1', '"a"', 'true', 'null', '-2.5e3']];
        yield 'objects and arrays are cut whole' => ['[{"a":[1,2,{"b":null}]},[3,[4]],{}]', ['{"a":[1,2,{"b":null}]}', '[3,[4]]', '{}']];
        yield 'a single element' => ['[{"a":1}]', ['{"a":1}']];
        yield 'commas and brackets inside strings' => ['["a,b","]","[","}","{",","]', ['"a,b"', '"]"', '"["', '"}"', '"{"', '","']];
        yield 'an escaped quote does not end the string' => ['["a\\"b,c","\\""]', ['"a\\"b,c"', '"\\""']];
        yield 'an escaped backslash before the closing quote ends the string' => ['["a\\\\","b"]', ['"a\\\\"', '"b"']];
        yield 'an escaped backslash then an escaped quote' => ['["\\\\\\"]",1]', ['"\\\\\\"]"', '1']];
        yield 'a unicode escape' => ['["\\u005d,\\u0022",2]', ['"\\u005d,\\u0022"', '2']];
        yield 'multibyte text' => ['["Стикер, ]","日本"]', ['"Стикер, ]"', '"日本"']];
        yield 'structural characters in an object key and value' => ['[{"a]":"[{,"},{"}":"]"}]', ['{"a]":"[{,"}', '{"}":"]"}']];
        yield 'white space of every kind around elements' => ["[\n\t1\r\n,\t2 \n]", ['1', '2']];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('documents')]
    public function cutsTheElementsOfTheArray(string $body, array $expected): void
    {
        self::assertSame($expected, $this->elements(RowSources::reader($body)));
    }

    /**
     * Every chunking of every document: chunks of 1..4 bytes, and every single split of the body into two.
     *
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('documents')]
    public function cutsTheSameElementsWhereverTheChunksSplitTheBody(string $body, array $expected): void
    {
        foreach ([1, 2, 3, 4] as $size) {
            self::assertSame($expected, $this->elements(RowSources::chunked($body, $size)), "chunks of {$size} bytes");
        }
        for ($at = 1; $at < \strlen($body); ++$at) {
            self::assertSame($expected, $this->elements(RowSources::chunked($body, $at)), "first chunk of {$at} bytes");
        }
    }

    #[Test]
    public function aBackslashAsTheLastByteOfAChunkEscapesTheFirstByteOfTheNext(): void
    {
        // Chunks of 4: `["a\` | `"b",` | `"c"]`; the quote after the backslash is data, not the end of the string.
        $body = '["a\\"b","c"]';

        self::assertSame(['"a\\"b"', '"c"'], $this->elements(RowSources::chunked($body, 4)));
    }

    #[Test]
    public function aBackslashPairSplitBetweenChunksIsOneEscape(): void
    {
        // `["a\\` | `\\","b"]` has chunks of 5: the first ends after one backslash of the pair `\\`.
        $body = '["a\\\\","b"]';

        self::assertSame(['"a\\\\"', '"b"'], $this->elements(RowSources::chunked($body, 5)));
    }

    #[Test]
    public function reportsTheIndexOfTheNextElementAsItIsCut(): void
    {
        $scanner = new JsonArrayScanner(RowSources::reader('[10,20,30]'), 'products');
        $indexes = [$scanner->index()];

        while ($scanner->next() !== null) {
            $indexes[] = $scanner->index();
        }

        self::assertSame([0, 1, 2, 3], $indexes);
    }

    #[Test]
    public function stillReturnsNullWhenAskedAgainAfterTheEnd(): void
    {
        $scanner = new JsonArrayScanner(RowSources::reader('[1]'), 'products');

        self::assertSame('1', $scanner->next());
        self::assertNull($scanner->next());
        self::assertNull($scanner->next());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAList(): iterable
    {
        yield 'a top-level object' => ['{"a":1}'];
        yield 'a top-level object after white space' => ["  \n\t{\"a\":1}"];
        yield 'a scalar' => ['5'];
        yield 'a string' => ['"x"'];
        yield 'an empty body' => [''];
        yield 'a whitespace-only body' => [" \r\n\t "];
    }

    #[Test]
    #[DataProvider('notAList')]
    public function aBodyThatIsNotAnArrayFailsAtThePathOfTheFile(string $body): void
    {
        foreach ([self::CHUNK, 1] as $size) {
            $e = TraceArguments::caught(fn(): array => $this->elements(RowSources::chunked($body, $size)));

            self::assertInstanceOf(DecodeException::class, $e);
            self::assertSame('products', $e->path, "chunks of {$size} bytes");
            self::assertStringContainsString('not a list of rows', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function badFrames(): iterable
    {
        yield 'a leading comma' => ['[,1]', 'products[0]', 0];
        yield 'a trailing comma' => ['[1,]', 'products[1]', 1];
        yield 'a doubled comma' => ['[1,,2]', 'products[1]', 1];
        yield 'a doubled comma after two elements' => ['[1,2,,3]', 'products[2]', 2];
        yield 'a comma only' => ['[,]', 'products[0]', 0];
        yield 'white space between commas' => ["[1, \n ,2]", 'products[1]', 1];
        yield 'a brace closing the array' => ['[1,2}', 'products[1]', 1];
        yield 'a brace right after the opening bracket' => ['[}', 'products[0]', 0];
        yield 'a body cut inside the first element' => ['[{"a"', 'products[0]', 0];
        yield 'a body cut after a comma' => ['[1,', 'products[1]', 1];
        yield 'a body cut inside the second element' => ['[{"a":1},{"a":', 'products[1]', 1];
        yield 'a body cut right after the opening bracket' => ['[', 'products[0]', 0];
        yield 'a body cut inside a string' => ['[1,"ab', 'products[1]', 1];
        yield 'a closing bracket inside an unterminated string' => ['[1,"ab]', 'products[1]', 1];
    }

    /**
     * Elements before the break are still returned: the failure is named at the element it belongs to.
     */
    #[Test]
    #[DataProvider('badFrames')]
    public function aBrokenFrameFailsAtTheIndexOfTheElementItBelongsTo(string $body, string $path, int $good): void
    {
        foreach ([self::CHUNK, 1] as $size) {
            $scanner = new JsonArrayScanner(RowSources::chunked($body, $size), 'products');
            $seen = 0;

            $e = TraceArguments::caught(function () use ($scanner, &$seen): void {
                while ($scanner->next() !== null) {
                    ++$seen;
                }
            });

            self::assertInstanceOf(DecodeException::class, $e);
            self::assertSame($path, $e->path, "chunks of {$size} bytes");
            self::assertSame($good, $seen, "chunks of {$size} bytes");
        }
    }

    #[Test]
    public function aCutShortBodyIsDescribedAsSuch(): void
    {
        $e = TraceArguments::caught(fn(): array => $this->elements(RowSources::reader('[1,2')));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringContainsString('cut short', $e->getMessage());
    }

    #[Test]
    public function theMessagesCarryNoBodyBytes(): void
    {
        $e = TraceArguments::caught(fn(): array => $this->elements(RowSources::reader('[{"secret":"abc"},,{"secret":"def"}]')));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringNotContainsString('secret', $e->getMessage());
        self::assertStringNotContainsString('abc', $e->getMessage());
        self::assertNotInstanceOf(\Throwable::class, $e->getPrevious());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function junkAfterTheList(): iterable
    {
        yield 'a letter' => ['[1]x'];
        yield 'a second array' => ['[1][2]'];
        yield 'a comma' => ['[1],'];
        yield 'a closing bracket' => ['[1]]'];
        yield 'a null byte' => ["[1]\0"];
        yield 'junk after white space' => ["[1] \n x"];
    }

    #[Test]
    #[DataProvider('junkAfterTheList')]
    public function dataAfterTheClosingBracketFailsAtThePathOfTheFileAfterTheLastElement(string $body): void
    {
        foreach ([self::CHUNK, 1] as $size) {
            $scanner = new JsonArrayScanner(RowSources::chunked($body, $size), 'products');
            self::assertSame('1', $scanner->next());

            $e = TraceArguments::caught(static fn(): mixed => $scanner->next());

            self::assertInstanceOf(DecodeException::class, $e);
            self::assertSame('products', $e->path, "chunks of {$size} bytes");
            self::assertStringContainsString('data after the end of the list', $e->getMessage());
        }
    }

    #[Test]
    public function whiteSpaceAfterTheArrayIsFineAcrossManyChunks(): void
    {
        $body = '[1,2]' . str_repeat(" \r\n\t", 5000);
        self::assertGreaterThan(self::CHUNK * 2, \strlen($body));

        self::assertSame(['1', '2'], $this->elements(RowSources::reader($body)));
    }

    #[Test]
    public function junkInALaterChunkAfterTheArrayIsFound(): void
    {
        $body = '[1,2]' . str_repeat(' ', self::CHUNK * 2) . 'x';

        $e = TraceArguments::caught(fn(): array => $this->elements(RowSources::reader($body)));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products', $e->path);
        self::assertStringContainsString('data after the end of the list', $e->getMessage());
    }

    #[Test]
    public function anElementOfExactlyTheLimitPasses(): void
    {
        $element = '"' . str_repeat('x', RowSource::MAX_ROW_BYTES - 2) . '"';
        self::assertSame(RowSource::MAX_ROW_BYTES, \strlen($element));

        self::assertSame([$element, '1'], $this->elements(RowSources::reader('[' . $element . ',1]')));
    }

    #[Test]
    public function anElementOfExactlyTheLimitPassesWhenAChunkEndsRightBeforeItsSeparator(): void
    {
        // 8192 bytes of small elements (one chunk), then the big element: after 129 chunks the buffer holds exactly
        // MAX_ELEMENT_BYTES of the element and its `,` has not arrived. The limit is "longer than", not "at least".
        $head = '[ ' . str_repeat('1,', 4095);
        self::assertSame(self::CHUNK, \strlen($head));
        $element = '"' . str_repeat('x', RowSource::MAX_ROW_BYTES - 2) . '"';

        $elements = $this->elements(RowSources::reader($head . $element . ',2]'));

        self::assertCount(4097, $elements);
        self::assertSame($element, $elements[4095] ?? null);
        self::assertSame('2', $elements[4096] ?? null);
    }

    #[Test]
    public function anElementWellOverTheLimitFailsBeforeTheEndOfTheBody(): void
    {
        // The limit is checked when the next chunk is wanted, so it holds to within one chunk.
        $element = '"' . str_repeat('x', RowSource::MAX_ROW_BYTES + self::CHUNK) . '"';
        $stream = TrackedStream::of('[' . $element . ',1]');
        $reader = BodyReader::open(new Response(200, [], $stream), rejectTrailingData: true);

        $e = TraceArguments::caught(fn(): array => $this->elements($reader));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[0]', $e->path);
        self::assertStringContainsString('too long', $e->getMessage());
        self::assertLessThan(\strlen($element), $stream->bytesRead(), 'the rest of the body was never read');
    }

    #[Test]
    public function anElementOfTheLimitAfterManySmallOnesPasses(): void
    {
        // Elements already returned do not count against the limit of the one being read.
        $big = '"' . str_repeat('y', RowSource::MAX_ROW_BYTES - 2) . '"';
        $body = '[' . implode(',', array_fill(0, 3000, '"small element"')) . ',' . $big . ']';

        $elements = $this->elements(RowSources::reader($body));

        self::assertCount(3001, $elements);
        self::assertSame($big, $elements[3000] ?? null);
    }

    #[Test]
    public function aFailureOfTheBodyIsTheTransportExceptionUnchanged(): void
    {
        $reader = RowSources::reader('[{"a":1},{"a":2}]', ['Content-Length' => '1000']);

        $e = TraceArguments::caught(fn(): array => $this->elements($reader));

        self::assertInstanceOf(TransportException::class, $e);
    }

    /**
     * @return list<string>
     */
    private function elements(BodyReader $reader): array
    {
        $scanner = new JsonArrayScanner($reader, 'products');
        $elements = [];
        while (($element = $scanner->next()) !== null) {
            $elements[] = $element;
        }

        return $elements;
    }
}
