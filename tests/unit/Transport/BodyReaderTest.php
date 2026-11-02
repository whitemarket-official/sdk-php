<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Tests\Support\TrackedStream;
use WhiteMarket\Sdk\Transport\BodyReader;

#[CoversClass(BodyReader::class)]
final class BodyReaderTest extends TestCase
{
    #[Test]
    public function readsAPlainBodyInChunksOfAtMost8KiB(): void
    {
        $reader = BodyReader::open(new Response(200, [], str_repeat('a', 20000)));

        $sizes = [];
        while (($chunk = $reader->next()) !== null) {
            $sizes[] = \strlen($chunk);
        }

        self::assertSame([8192, 8192, 3616], $sizes);
    }

    #[Test]
    public function anEmptyPlainBodyHasNoChunks(): void
    {
        self::assertNull(BodyReader::open(new Response(200))->next());
    }

    #[Test]
    public function keepsReturningNullAfterTheEnd(): void
    {
        $reader = BodyReader::open(new Response(200, [], 'x'));

        self::assertSame('x', $reader->next());
        self::assertNull($reader->next());
        self::assertNull($reader->next());
    }

    #[Test]
    public function aLenientReaderEndsAtACorruptGzipBodyInsteadOfFailing(): void
    {
        $reader = BodyReader::open(new Response(200, ['Content-Encoding' => 'gzip'], 'not gzip'), lenient: true);

        self::assertNull($reader->next());
    }

    #[Test]
    public function aLenientReaderEndsAtATruncatedGzipBodyAfterTheDecodedPart(): void
    {
        $plain = implode('', array_map(static fn(int $i): string => hash('sha256', (string) $i), range(1, 1000)));
        $truncated = substr((string) gzencode($plain), 0, 20000);

        $decoded = BodyReader::errorExcerpt(new Response(200, ['Content-Encoding' => 'gzip'], $truncated), 10_000_000);

        self::assertNotSame('', $decoded);
        self::assertStringStartsWith($decoded, $plain);
        self::assertLessThan(\strlen($plain), \strlen($decoded));
    }

    #[Test]
    public function aStrictReaderFailsAtATruncatedGzipBody(): void
    {
        $truncated = substr((string) gzencode(str_repeat('x', 5000)), 0, 20);

        $this->expectException(TransportException::class);

        BodyReader::readAll(new Response(200, ['Content-Encoding' => 'gzip'], $truncated), 10_000_000, 'limit');
    }

    #[Test]
    public function cutsALenientReadAtTheLimit(): void
    {
        $decoded = BodyReader::errorExcerpt(new Response(200, [], str_repeat('a', 300)), 100);

        self::assertSame(str_repeat('a', 100), $decoded);
    }

    #[Test]
    public function namesTheLimitInTheMessageOfAStrictFailure(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('exceeds 100 bytes (theLimit)');

        BodyReader::readAll(new Response(200, [], str_repeat('a', 101)), 100, 'theLimit');
    }

    #[Test]
    public function acceptsTheExactLimit(): void
    {
        self::assertSame(100, \strlen(BodyReader::readAll(new Response(200, [], str_repeat('a', 100)), 100, 'limit')));
    }

    #[Test]
    public function closingTheReaderClosesTheStreamAndEndsTheBody(): void
    {
        $response = new Response(200, [], 'x');
        $reader = BodyReader::open($response);
        $reader->close();

        self::assertNull($reader->next());
        $this->expectException(\RuntimeException::class);
        $response->getBody()->read(1);
    }

    /**
     * @return string a body of many chunks in which every byte position is distinguishable
     */
    private function patterned(int $bytes): string
    {
        return substr(implode('', array_map(static fn(int $i): string => hash('sha256', (string) $i), range(1, intdiv($bytes, 64) + 1))), 0, $bytes);
    }

    #[Test]
    public function readAllKeepsEveryChunkOfALargeBody(): void
    {
        $body = $this->patterned(20000);

        self::assertSame($body, BodyReader::readAll(new Response(200, [], $body), 100_000, 'limit'));
    }

    #[Test]
    public function readAllClosesTheStreamWhenTheLimitIsExceeded(): void
    {
        $stream = TrackedStream::of($this->patterned(20000));

        try {
            BodyReader::readAll(new Response(200, [], $stream), 9000, 'limit');
            self::fail('The limit was not enforced.');
        } catch (TransportException) {
            self::assertTrue($stream->wasClosed());
        }
    }

    #[Test]
    public function anExcerptCutAtTheLimitKeepsTheStartInOrderAcrossChunks(): void
    {
        $body = $this->patterned(20000);

        self::assertSame(substr($body, 0, 10000), BodyReader::errorExcerpt(new Response(500, [], $body), 10000));
        self::assertSame('abcd', BodyReader::errorExcerpt(new Response(500, [], 'abcdefghij'), 4));
    }

    #[Test]
    public function anExcerptCutAtTheLimitClosesTheStream(): void
    {
        $stream = TrackedStream::of($this->patterned(20000));

        BodyReader::errorExcerpt(new Response(500, [], $stream), 100);

        self::assertTrue($stream->wasClosed());
    }

    #[Test]
    public function theReaderClosesTheStreamAtTheEndOfTheBody(): void
    {
        $stream = TrackedStream::of('abc');
        $reader = BodyReader::open(new Response(200, [], $stream));

        self::assertSame('abc', $reader->next());
        self::assertFalse($stream->wasClosed(), 'the body is not over until a read says so');
        self::assertNull($reader->next());
        self::assertTrue($stream->wasClosed());
    }

    #[Test]
    public function theReaderClosesTheStreamWhenAChunkFails(): void
    {
        $stream = TrackedStream::of('abcdefghij');
        $reader = BodyReader::open(new Response(200, ['Content-Length' => '4'], $stream));

        try {
            $reader->next();
            self::fail('The body is longer than announced.');
        } catch (TransportException $e) {
            self::assertStringContainsString('longer than Content-Length', $e->getMessage());
            self::assertTrue($stream->wasClosed());
        }
    }

    #[Test]
    public function aCorruptGzipStreamFailsAStrictReaderWithTheGzipMessage(): void
    {
        $corrupt = substr((string) gzencode('hello'), 0, 10) . str_repeat("\xFF", 40);

        $e = null;
        try {
            BodyReader::readAll(new Response(200, ['Content-Encoding' => 'gzip'], $corrupt), 1000, 'limit');
        } catch (TransportException $e) {
        }

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('The response body is not valid gzip.', $e->getMessage());
    }

    #[Test]
    public function trailingDataInALaterCompressedSliceIsStillDetected(): void
    {
        // Incompressible: the member spans several 1 KiB slices, and the trailing bytes come with the last one.
        $incompressible = $this->patterned(6000);
        $member = (string) gzencode($incompressible, 0);

        $reader = BodyReader::open(new Response(200, ['Content-Encoding' => 'gzip'], $member . 'ZZ'), rejectTrailingData: true);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('after the end of the gzip stream');
        while ($reader->next() !== null) {
        }
    }

    #[Test]
    public function aDefaultReaderIgnoresALongTailAfterTheFirstGzipMember(): void
    {
        $body = gzencode('AAAA') . str_repeat('Z', 3000);

        $reader = BodyReader::open(new Response(200, ['Content-Encoding' => 'gzip'], $body));

        self::assertSame('AAAA', $reader->next());
        self::assertNull($reader->next());
    }

    #[Test]
    public function openIgnoresDataAfterTheFirstGzipMemberUnlessAskedNotTo(): void
    {
        $reader = BodyReader::open(new Response(200, ['Content-Encoding' => 'gzip'], gzencode('AAAA') . gzencode('BBBB')));

        self::assertSame('AAAA', $reader->next());
        self::assertNull($reader->next());
    }

    /**
     * A gzip stream of `$megabytes` MiB of zeros: a few tens of KiB that inflate to a lot.
     */
    private function bomb(int $megabytes): string
    {
        $deflate = deflate_init(\ZLIB_ENCODING_GZIP, ['level' => 9]);
        self::assertInstanceOf(\DeflateContext::class, $deflate);
        $zeros = str_repeat("\0", 1024 * 1024);
        $bomb = '';
        for ($i = 0; $i < $megabytes; ++$i) {
            $bomb .= (string) deflate_add($deflate, $zeros, \ZLIB_NO_FLUSH);
        }

        return $bomb . deflate_add($deflate, '', \ZLIB_FINISH);
    }

    #[Test]
    public function oneDecodeStepNeverExceedsTheDocumentedBound(): void
    {
        $reader = BodyReader::open(new Response(200, ['Content-Encoding' => 'gzip'], $this->bomb(50)));

        $peak = 0;
        $total = 0;
        while (($chunk = $reader->next()) !== null) {
            $peak = max($peak, \strlen($chunk));
            $total += \strlen($chunk);
        }

        self::assertSame(50 * 1024 * 1024, $total);
        // 1024 compressed bytes in, at most 1032 bytes out per compressed byte (deflate's best ratio): 1056768.
        self::assertLessThanOrEqual(1_056_768, BodyReader::MAX_STEP_BYTES);
        self::assertLessThanOrEqual(BodyReader::MAX_STEP_BYTES, $peak);
    }

    #[Test]
    public function aBombAgainstATinyLimitFailsWithoutHoldingMoreThanOneStep(): void
    {
        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('exceeds 1 bytes (limit)');

        BodyReader::readAll(new Response(200, ['Content-Encoding' => 'gzip'], $this->bomb(50)), 1, 'limit');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function trailingData(): iterable
    {
        $big = implode('', array_map(static fn(int $i): string => hash('sha256', (string) $i), range(1, 1000)));
        yield 'one member -> fine' => [(string) gzencode('AAAA'), false];
        yield 'second member' => [gzencode('AAAA') . gzencode('BBBB'), true];
        yield 'garbage after the member' => [gzencode('AAAA') . str_repeat('Z', 3), true];
        yield 'one NUL byte after the member' => [gzencode('AAAA') . "\0", true];
        // A stored (level 0) member is its data + 23 bytes: sizes chosen so the member ends where the reader's steps end.
        $endsOnASlice = (string) gzencode(str_repeat('A', 3 * 1024 - 23), 0);
        $endsOnARead = (string) gzencode(str_repeat('A', 8192 - 23), 0);
        yield 'garbage right after a member that ends on a 1 KiB step, same read' => [$endsOnASlice . 'ZZZ', true];
        yield 'garbage right after a member that ends on a read boundary' => [$endsOnARead . 'ZZZ', true];
        yield 'second member right after a member that ends on a read boundary' => [$endsOnARead . gzencode('BBBB'), true];
        yield 'a member that ends on a read boundary, nothing after' => [$endsOnARead, false];
        yield 'a member that ends on a 1 KiB step, nothing after' => [$endsOnASlice, false];
        yield 'garbage in a later read' => [gzencode($big) . str_repeat('Z', 20000), true];
    }

    #[Test]
    #[DataProvider('trailingData')]
    public function anExportReaderRejectsDataAfterTheEndOfTheGzipStream(string $body, bool $rejected): void
    {
        $reader = BodyReader::open(new Response(200, ['Content-Encoding' => 'gzip'], $body), rejectTrailingData: true);

        if ($rejected) {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage('data after the end of the gzip stream');
        }
        $decoded = '';
        while (($chunk = $reader->next()) !== null) {
            $decoded .= $chunk;
        }

        self::assertStringStartsWith('A', $decoded);
    }

    #[Test]
    public function aDefaultReaderIgnoresDataAfterTheFirstGzipMember(): void
    {
        $body = gzencode('AAAA') . gzencode('BBBB');

        $decoded = BodyReader::readAll(new Response(200, ['Content-Encoding' => 'gzip'], $body), 1000, 'limit');

        self::assertSame('AAAA', $decoded);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function announcedLengths(): iterable
    {
        yield 'as announced' => ['abcdef', '6', true];
        yield 'one byte short' => ['abcde', '6', false];
        yield 'one byte more' => ['abcdefg', '6', false];
        yield 'nothing arrived' => ['', '6', false];
        yield 'announced zero, body empty' => ['', '0', true];
        yield 'identical repeats are one value (RFC 9110 8.6), body complete' => ['abcdef', '6, 6', true];
        yield 'identical repeats without a space' => ['abcdef', '6,6,6', true];
        yield 'identical repeats, body one byte short' => ['abcde', '6, 6', false];
        yield 'identical repeats, body one byte more' => ['abcdefg', '6, 6', false];
        yield 'identical repeats, much shorter body' => ['0123456789', '1000, 1000', false];
        yield 'different repeats' => ['abcdef', '1000, 999', false];
        yield 'different repeats, one equal to the body' => ['abcdef', '6, 7', false];
        yield 'a leading plus' => ['0123456789', '+1000', false];
        yield 'a leading plus, equal to the body' => ['abcdef', '+6', false];
        yield 'a negative number' => ['abcdef', '-6', false];
        yield 'a number with a decimal point' => ['abcdef', '6.0', false];
        yield 'hexadecimal' => ['abcdef', '0x6', false];
        yield 'letters' => ['abc', 'many', false];
        yield 'an empty element in the list' => ['abcdef', '6,,6', false];
        yield 'a trailing comma' => ['abcdef', '6,', false];
        yield 'leading zeros are digits' => ['abcdef', '006', true];
        yield 'surrounding whitespace is trimmed' => ['abcdef', ' 6 ', true];
        yield 'too many digits to be a length' => ['abcdef', '1234567890123456789', false];
        yield 'an empty value' => ['abcdef', '', false];
    }

    #[Test]
    #[DataProvider('announcedLengths')]
    public function anIdentityBodyMustMatchItsContentLength(string $body, string $announced, bool $accepted): void
    {
        $response = new Response(200, ['Content-Length' => $announced], $body);

        if (!$accepted) {
            $this->expectException(TransportException::class);
            $this->expectExceptionMessage('Content-Length');
        }

        self::assertSame($body, BodyReader::readAll($response, 100, 'limit'));
    }

    #[Test]
    public function withoutContentLengthThereIsNothingToCheck(): void
    {
        self::assertSame('abcdef', BodyReader::readAll(new Response(200, [], 'abcdef'), 100, 'limit'));
    }

    #[Test]
    public function aLenientReadIgnoresAMalformedContentLength(): void
    {
        $response = new Response(500, ['Content-Length' => '+1000, 999'], 'partial error body');

        self::assertSame('partial error body', BodyReader::errorExcerpt($response, 100));
    }

    #[Test]
    public function aLenientReadKeepsWhatArrivedOfAShortBody(): void
    {
        $response = new Response(500, ['Content-Length' => '1000'], 'partial error body');

        self::assertSame('partial error body', BodyReader::errorExcerpt($response, 100));
    }

    /**
     * @return iterable<string, array{string, \Closure(int): string, bool, bool}> bytes after the gzip member, the header
     *                                                                            from the length of the member, accepted, reject trailing data
     */
    public static function gzipAnnouncedLengths(): iterable
    {
        foreach ([false, true] as $reject) {
            $mode = $reject ? ' (exports)' : '';
            yield 'raw length as announced' . $mode => ['', static fn(int $raw): string => (string) $raw, true, $reject];
            yield 'one byte more than arrived' . $mode => ['', static fn(int $raw): string => (string) ($raw + 1), false, $reject];
            yield 'one byte less than arrived' . $mode => ['', static fn(int $raw): string => (string) ($raw - 1), false, $reject];
            yield 'much more than arrived' . $mode => ['', static fn(int $raw): string => (string) ($raw + 50), false, $reject];
            yield 'letters' . $mode => ['', static fn(): string => 'abc', false, $reject];
            yield 'a leading plus' . $mode => ['', static fn(int $raw): string => '+' . $raw, false, $reject];
            yield 'different repeats' . $mode => ['', static fn(int $raw): string => $raw . ', ' . ($raw + 1), false, $reject];
            yield 'identical repeats' . $mode => ['', static fn(int $raw): string => $raw . ', ' . $raw, true, $reject];
        }
        yield 'ignored bytes after the member count too' => ['junk', static fn(int $raw): string => (string) ($raw + 4), true, false];
        yield 'ignored bytes after the member are still a longer body' => ['junk', static fn(int $raw): string => (string) $raw, false, false];
        yield 'bytes after the member are refused by an export read whatever the length' => ['junk', static fn(int $raw): string => (string) ($raw + 4), false, true];
    }

    /**
     * @param \Closure(int): string $announce
     */
    #[Test]
    #[DataProvider('gzipAnnouncedLengths')]
    public function aGzipBodyMustMatchItsContentLengthInRawBytes(string $trailing, \Closure $announce, bool $accepted, bool $rejectTrailingData): void
    {
        $gzip = (string) gzencode('AAAA');
        $response = new Response(200, ['Content-Encoding' => 'gzip', 'Content-Length' => $announce(\strlen($gzip))], $gzip . $trailing);

        if (!$accepted) {
            $this->expectException(TransportException::class);
        }

        self::assertSame('AAAA', BodyReader::readAll($response, 100, 'limit', $rejectTrailingData));
    }

    #[Test]
    public function aLenientReadOfAGzipBodyIgnoresItsContentLength(): void
    {
        $response = new Response(500, ['Content-Encoding' => 'gzip', 'Content-Length' => 'abc'], (string) gzencode('AAAA'));

        self::assertSame('AAAA', BodyReader::errorExcerpt($response, 100));
    }

    #[Test]
    public function anErrorExcerptIsCappedAtOneMiBWhateverTheCallerAllows(): void
    {
        $response = new Response(500, [], str_repeat('a', 1024 * 1024 + 10));

        self::assertSame(1024 * 1024, \strlen(BodyReader::errorExcerpt($response, 10 * 1024 * 1024)));
    }
}
