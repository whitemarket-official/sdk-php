<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export\Rows;

use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Export\Rows\CsvRows;
use WhiteMarket\Sdk\Export\Rows\GuardedRowSource;
use WhiteMarket\Sdk\Export\Rows\JsonMachineRows;
use WhiteMarket\Sdk\Export\Rows\RowIterator;
use WhiteMarket\Sdk\Export\Rows\RowSource;
use WhiteMarket\Sdk\Tests\Support\HostileResponse;
use WhiteMarket\Sdk\Tests\Support\RowSources;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;
use WhiteMarket\Sdk\Tests\Support\TrackedStream;
use WhiteMarket\Sdk\Transport\BodyReader;

#[CoversClass(RowIterator::class)]
#[CoversClass(GuardedRowSource::class)]
final class RowIteratorTest extends TestCase
{
    #[Test]
    public function yieldsTheMappedRowsKeyedFromZeroInFileOrder(): void
    {
        $rows = new RowIterator(
            new JsonMachineRows(RowSources::reader('[10,20,30]'), 'products'),
            static fn(mixed $raw, string $path): string => $path . '=' . json_encode($raw),
            'products',
        );

        self::assertSame([0 => 'products[0]=10', 1 => 'products[1]=20', 2 => 'products[2]=30'], iterator_to_array($rows, true));
    }

    #[Test]
    public function aMapperThatReturnsNullSkipsTheRowAndTheKeysStayTheFileIndex(): void
    {
        $paths = [];
        $rows = new RowIterator(
            new JsonMachineRows(RowSources::reader('[10,0,0,20,0]'), 'products'),
            static function (mixed $raw, string $path) use (&$paths): ?string {
                $paths[] = $path;

                return $raw === 0 ? null : $path;
            },
            'products',
        );

        self::assertSame([0 => 'products[0]', 3 => 'products[3]'], iterator_to_array($rows, true));
        self::assertSame(['products[0]', 'products[1]', 'products[2]', 'products[3]', 'products[4]'], $paths);
    }

    #[Test]
    public function aFileOfOnlySkippedRowsIsEmpty(): void
    {
        $rows = new RowIterator(
            new JsonMachineRows(RowSources::reader('[0,0]'), 'products'),
            static fn(): mixed => null,
            'products',
        );

        $rows->rewind();

        self::assertFalse($rows->valid());
        self::assertNull($rows->current());
    }

    #[Test]
    public function keysCountTheRowsNotTheLinesOfACsvFile(): void
    {
        $rows = new RowIterator(
            new CsvRows(RowSources::reader("\na\n\n1\n\n2\n"), 'products'),
            static fn(mixed $raw): mixed => $raw,
            'products',
        );

        self::assertSame([0 => ['a' => '1'], 1 => ['a' => '2']], iterator_to_array($rows, true));
    }

    #[Test]
    public function anEmptyListIsInvalidFromTheStart(): void
    {
        $rows = $this->jsonIterator('[]');

        $rows->rewind();

        self::assertFalse($rows->valid());
        self::assertNull($rows->current());
        self::assertSame([], iterator_to_array($rows, true));
    }

    #[Test]
    public function readsNothingUntilIterationStarts(): void
    {
        $stream = TrackedStream::of('[1,2]');

        $rows = new RowIterator(
            new JsonMachineRows(BodyReader::open(new Response(200, [], $stream), rejectTrailingData: true), 'products'),
            static fn(mixed $raw): mixed => $raw,
            'products',
        );

        self::assertSame(0, $stream->bytesRead());
        $rows->rewind();

        self::assertSame(1, $rows->current());
        self::assertGreaterThan(0, $stream->bytesRead());
    }

    #[Test]
    public function aSecondRewindAfterTheStartIsALogicException(): void
    {
        $rows = $this->jsonIterator('[1,2]');
        $rows->rewind();

        $e = TraceArguments::caught(static fn() => $rows->rewind());

        self::assertInstanceOf(\LogicException::class, $e);
        self::assertSame(1, $rows->current(), 'the iterator stayed where it was');
    }

    #[Test]
    public function iteratingAFinishedIteratorAgainIsALogicException(): void
    {
        $rows = $this->jsonIterator('[1,2]');
        iterator_to_array($rows, true);

        $e = TraceArguments::caught(static fn(): array => iterator_to_array($rows, true));

        self::assertInstanceOf(\LogicException::class, $e);
    }

    #[Test]
    public function afterTheEndItIsInvalidAndStaysSo(): void
    {
        $rows = $this->jsonIterator('[7]');
        $rows->rewind();
        $rows->next();

        self::assertFalse($rows->valid());
        self::assertNull($rows->current());

        $rows->next();

        self::assertFalse($rows->valid());
        self::assertNull($rows->current());
    }

    #[Test]
    public function keyAndCurrentFollowTheRowsAsTheIteratorMoves(): void
    {
        $rows = $this->jsonIterator('["a","b"]');
        $seen = [];

        for ($rows->rewind(); $rows->valid(); $rows->next()) {
            $seen[] = [$rows->key(), $rows->current()];
        }

        self::assertSame([[0, 'a'], [1, 'b']], $seen);
    }

    #[Test]
    public function aFailureOfTheFirstRowIsThrownByRewind(): void
    {
        $rows = $this->jsonIterator('{"a":1}');

        $e = TraceArguments::caught(static fn() => $rows->rewind());

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products', $e->path);
    }

    #[Test]
    public function aFailureOfALaterRowIsThrownByNextAfterTheGoodRows(): void
    {
        $rows = $this->jsonIterator('[1,2,{a}]');
        $rows->rewind();
        $rows->next();
        self::assertSame(2, $rows->current());

        $e = TraceArguments::caught(static fn() => $rows->next());

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[2]', $e->path);
        self::assertFalse($rows->valid(), 'no stale row after a failure');
        self::assertNull($rows->current());
    }

    #[Test]
    public function aFailureOfTheBodyIsATransportExceptionFromTheIterator(): void
    {
        $body = '[1,2]';
        $rows = new RowIterator(
            new JsonMachineRows(RowSources::reader($body, ['Content-Length' => '1000']), 'products'),
            static fn(mixed $raw): mixed => $raw,
            'products',
        );

        $e = TraceArguments::caught(static fn(): array => iterator_to_array($rows, true));

        self::assertInstanceOf(TransportException::class, $e);
    }

    #[Test]
    public function aMapperDecodeExceptionPropagatesAndClosesTheBody(): void
    {
        $stream = TrackedStream::of('[1,2,3,' . str_repeat('4,', 20000) . '5]');
        $rows = new RowIterator(
            new JsonMachineRows(BodyReader::open(new Response(200, [], $stream), rejectTrailingData: true), 'products'),
            static fn(mixed $raw, string $path): mixed => $raw === 2 ? throw new DecodeException($path . '.id', 'bad') : $raw,
            'products',
        );
        $seen = [];

        $e = TraceArguments::caught(static function () use ($rows, &$seen): void {
            foreach ($rows as $row) {
                $seen[] = $row;
            }
        });

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[1].id', $e->path);
        self::assertSame([1], $seen);
        self::assertTrue($stream->wasClosed(), 'closed at once, not when the iterator is dropped');
        self::assertLessThan(\strlen('[1,2,3,' . str_repeat('4,', 20000) . '5]'), $stream->bytesRead());
    }

    #[Test]
    public function droppingTheIteratorBeforeTheEndClosesTheBody(): void
    {
        $stream = TrackedStream::of('[' . implode(',', range(1, 5000)) . ']');
        $reader = BodyReader::open(new Response(200, [], $stream), rejectTrailingData: true);
        $rows = new RowIterator(new JsonMachineRows($reader, 'products'), static fn(mixed $raw): mixed => $raw, 'products');

        foreach ($rows as $row) {
            self::assertSame(1, $row);

            break;
        }
        self::assertFalse($stream->wasClosed(), 'still open while the iterator is alive');
        unset($rows);

        self::assertTrue($stream->wasClosed());
        self::assertNull($reader->next());
    }

    #[Test]
    public function aFailedLateCloseIsNotReportedByTheDestructor(): void
    {
        $body = '[' . implode(',', range(1, 5000)) . ']';
        $response = HostileResponse::at('close', new \RuntimeException('close failed'), body: $body);
        $rows = new RowIterator(
            new JsonMachineRows(BodyReader::open($response, rejectTrailingData: true), 'products'),
            static fn(mixed $raw): mixed => $raw,
            'products',
        );
        $rows->rewind();

        $drop = static function () use (&$rows): void {
            $rows = null;
        };
        $thrown = null;
        try {
            $drop();
        } catch (\Throwable $thrown) {
        }

        self::assertNull($thrown);
    }

    #[Test]
    public function closesAnyBareSourceExactlyOnceAtTheEndAndAfterTheIteratorIsDropped(): void
    {
        $source = new class implements RowSource {
            private int $closes = 0;

            private int $left = 2;

            public function next(): bool
            {
                return $this->left-- > 0;
            }

            public function row(): mixed
            {
                return $this->left;
            }

            public function close(): void
            {
                ++$this->closes;
            }

            public function closes(): int
            {
                return $this->closes;
            }
        };
        $rows = new RowIterator($source, static fn(mixed $raw): mixed => $raw, 'products');

        iterator_to_array($rows, true);
        self::assertSame(1, $source->closes(), 'closed at the end of the rows');

        unset($rows);
        self::assertSame(1, $source->closes(), 'the drop does not close it again');
    }

    /**
     * @return RowIterator<mixed>
     */
    private function jsonIterator(string $body): RowIterator
    {
        return new RowIterator(
            new JsonMachineRows(RowSources::reader($body), 'products'),
            static fn(mixed $raw): mixed => $raw,
            'products',
        );
    }
}
