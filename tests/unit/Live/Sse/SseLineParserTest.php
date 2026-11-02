<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live\Sse;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Live\Sse\SseLineParser;

#[CoversClass(SseLineParser::class)]
final class SseLineParserTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function drain(SseLineParser $parser): array
    {
        $events = [];
        while (($event = $parser->next()) !== null) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @param list<string> $chunks
     *
     * @return list<string>
     */
    private function parse(array $chunks, int $max = 1024): array
    {
        $parser = new SseLineParser($max);
        $events = [];
        foreach ($chunks as $chunk) {
            $parser->feed($chunk);
            array_push($events, ...$this->drain($parser));
        }

        return $events;
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function streams(): iterable
    {
        yield 'centrifugo event' => ["data: {\"a\":1}\n\n", ['{"a":1}']];
        yield 'two events' => ["data: one\n\ndata: two\n\n", ['one', 'two']];
        yield 'crlf' => ["data: one\r\n\r\ndata: two\r\n\r\n", ['one', 'two']];
        yield 'cr only' => ["data: one\r\rdata: two\r\r", ['one', 'two']];
        yield 'mixed endings' => ["data: one\r\n\ndata: two\n\r\ndata: three\r\r", ['one', 'two', 'three']];
        yield 'multi-line data joined with a line feed' => ["data: a\ndata: b\n\n", ["a\nb"]];
        yield 'empty data line inside' => ["data: a\ndata:\ndata: b\n\n", ["a\n\nb"]];
        yield 'one leading space stripped only' => ["data:  two\n\n", [' two']];
        yield 'no space after the colon' => ["data:x\n\n", ['x']];
        yield 'bare data field' => ["data\n\n", ['']];
        yield 'empty data' => ["data:\n\n", ['']];
        yield 'comment ignored' => [": keepalive\n\ndata: x\n\n", ['x']];
        yield 'comment inside an event' => ["data: a\n: c\ndata: b\n\n", ["a\nb"]];
        yield 'other fields ignored' => ["event: m\nid: 7\nretry: 100\nfoo: bar\ndata: x\n\n", ['x']];
        yield 'event without data is not dispatched' => ["event: m\n\nid: 1\n\n", []];
        yield 'blank lines only' => ["\n\n\r\n\r\n", []];
        yield 'incomplete event dropped' => ["data: a\n\ndata: b\n", ['a']];
        yield 'incomplete line dropped' => ["data: a\n\ndata: b", ['a']];
        yield 'empty ping object' => ["data: {}\n\n", ['{}']];
        yield 'colon inside the value' => ["data: a:b:c\n\n", ['a:b:c']];
        yield 'field name is case sensitive' => ["DATA: x\n\n", []];
        yield 'lookalike field' => ["data2: x\ndatax: y\n\n", []];
        yield 'bom-free utf8 passes through' => ["data: \u{41F}\u{440}\n\n", ["\u{41F}\u{440}"]];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('streams')]
    public function parsesTheWholeStreamInOneChunk(string $stream, array $expected): void
    {
        self::assertSame($expected, $this->parse([$stream]));
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('streams')]
    public function parsesTheStreamByteByByte(string $stream, array $expected): void
    {
        self::assertSame($expected, $this->parse(str_split($stream)));
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('streams')]
    public function parsesTheStreamSplitAtEveryPosition(string $stream, array $expected): void
    {
        for ($cut = 0; $cut <= \strlen($stream); ++$cut) {
            self::assertSame($expected, $this->parse([substr($stream, 0, $cut), substr($stream, $cut)]), "cut at $cut");
        }
    }

    #[Test]
    public function keepsACrLfSplitBetweenChunksAsOneLineEnd(): void
    {
        // If the LF after the CR were taken for its own empty line, "a" would be dispatched before "b" is read.
        self::assertSame(["a\nb"], $this->parse(["data: a\r", "\ndata: b\r\n\r", "\n"]));
    }

    #[Test]
    public function aLoneCrAtTheEndOfAChunkIsALineEndNotAnEventEnd(): void
    {
        $parser = new SseLineParser(1024);
        $parser->feed("data: a\r");
        self::assertNull($parser->next());
        $parser->feed("\r");
        self::assertSame('a', $parser->next());
    }

    #[Test]
    public function aLoneCrFollowedByAnotherChunkStartingWithTextEndsTheLine(): void
    {
        self::assertSame(['a'], $this->parse(["data: a\r", "\rdata: x"]));
    }

    #[Test]
    public function splitsAtRandomPointsGiveTheSameEvents(): void
    {
        $stream = '';
        $expected = [];
        mt_srand(20261101);
        $endings = ["\n", "\r\n", "\r"];
        for ($i = 0; $i < 200; ++$i) {
            $lines = mt_rand(1, 3);
            $data = [];
            for ($j = 0; $j < $lines; ++$j) {
                $value = \chr(mt_rand(97, 122)) . str_repeat('x', mt_rand(0, 30));
                $data[] = $value;
                $stream .= (mt_rand(0, 1) === 1 ? 'data: ' : 'data:') . $value . $endings[mt_rand(0, 2)];
                if (mt_rand(0, 4) === 0) {
                    $stream .= ': comment' . $endings[mt_rand(0, 2)] . 'event: e' . $endings[mt_rand(0, 2)];
                }
            }
            // A bare LF right after a CR would fuse into one CRLF, so the blank line must not start with LF then.
            $stream .= str_ends_with($stream, "\r") ? $endings[mt_rand(1, 2)] : $endings[mt_rand(0, 2)];
            $expected[] = implode("\n", $data);
        }

        self::assertSame($expected, $this->parse([$stream], 4096));

        for ($round = 0; $round < 100; ++$round) {
            $chunks = [];
            $pos = 0;
            while ($pos < \strlen($stream)) {
                $size = mt_rand(1, 40);
                $chunks[] = substr($stream, $pos, $size);
                $pos += $size;
            }
            self::assertSame($expected, $this->parse($chunks, 4096), "round $round");
        }
    }

    #[Test]
    public function acceptsAnEventOfExactlyTheCap(): void
    {
        $payload = str_repeat('a', 1024);

        self::assertSame([$payload], $this->parse(["data: $payload\n\n"]));
        self::assertSame([$payload], $this->parse(["data:$payload\n\n"]));
    }

    #[Test]
    public function rejectsAnEventOfTheCapPlusOne(): void
    {
        $this->expectException(TransportException::class);

        $this->parse(['data: ' . str_repeat('a', 1025) . "\n\n"]);
    }

    #[Test]
    public function rejectsAnEventOfTheCapPlusOneWithoutASpace(): void
    {
        $this->expectException(TransportException::class);

        $this->parse(['data:' . str_repeat('a', 1025) . "\n\n"]);
    }

    #[Test]
    public function acceptsMultiLineDataOfExactlyTheCapJoinedLength(): void
    {
        // 511 + 1 line feed + 512 = 1024
        $joined = str_repeat('a', 511) . "\n" . str_repeat('b', 512);

        self::assertSame([$joined], $this->parse(['data: ' . str_repeat('a', 511) . "\ndata: " . str_repeat('b', 512) . "\n\n"]));
    }

    #[Test]
    public function rejectsMultiLineDataOverTheCapJoinedLength(): void
    {
        $this->expectException(TransportException::class);

        $this->parse(['data: ' . str_repeat('a', 511) . "\ndata: " . str_repeat('b', 513) . "\n\n"]);
    }

    #[Test]
    public function rejectsALongCommentLine(): void
    {
        $this->expectException(TransportException::class);

        $this->parse([': ' . str_repeat('c', 1024 + 6) . "\n"]);
    }

    #[Test]
    public function rejectsALongUnterminatedLineWithoutWaitingForItsEnd(): void
    {
        $parser = new SseLineParser(1024);
        $parser->feed('data: ' . str_repeat('a', 1000));

        $this->expectException(TransportException::class);
        $parser->feed(str_repeat('a', 100));
    }

    #[Test]
    public function theCapErrorCarriesNoServerBytes(): void
    {
        try {
            $this->parse(['data: SECRET' . str_repeat('a', 2000) . "\n\n"]);
            self::fail('No exception.');
        } catch (TransportException $e) {
            self::assertSame('A live event line exceeds the size limit.', $e->getMessage());
        }
        try {
            $this->parse(['data: SECRET' . str_repeat('a', 1000) . "\ndata: " . str_repeat('a', 100) . "\n\n"]);
            self::fail('No exception.');
        } catch (TransportException $e) {
            self::assertSame('A live event exceeds the size limit.', $e->getMessage());
        }
    }

    #[Test]
    public function rejectsANonPositiveCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SseLineParser(0);
    }

    #[Test]
    public function memoryStaysFlatOverManyEvents(): void
    {
        $parser = new SseLineParser(1024);
        $chunk = str_repeat("data: {\"price\":\"1.00\"}\n\n", 100);

        $this->feedRounds($parser, $chunk); // warm up
        gc_collect_cycles();
        $before = memory_get_usage();
        $total = 0;
        for ($round = 0; $round < 10; ++$round) {
            $total += $this->feedRounds($parser, $chunk); // 10 x 10k = 100k events
        }
        gc_collect_cycles();
        $delta = memory_get_usage() - $before;

        self::assertSame(100_000, $total);
        self::assertLessThan(256 * 1024, $delta);
    }

    /**
     * 100 rounds of 100 events; returns the number of events taken.
     */
    private function feedRounds(SseLineParser $parser, string $chunk): int
    {
        $count = 0;
        for ($i = 0; $i < 100; ++$i) {
            $parser->feed($chunk);
            while ($parser->next() !== null) {
                ++$count;
            }
        }

        return $count;
    }
}
