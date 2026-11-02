<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Tests\Support\ScriptedStream;
use WhiteMarket\Sdk\Transport\ClientStream;

#[CoversClass(ClientStream::class)]
final class ClientStreamTest extends TestCase
{
    #[Test]
    public function readSliceReturnsTheBytesOfASuccessfulRead(): void
    {
        $stream = new ClientStream(Utils::streamFor('abcdef'));

        self::assertSame('abc', $stream->readSlice(3));
    }

    #[Test]
    public function readSliceIgnoresTheTimedOutFlagAfterASuccessfulRead(): void
    {
        // On chunked bodies the flag stays true on successful reads.
        $stream = new ClientStream(ScriptedStream::expiringSlices(['data']));

        self::assertSame('data', $stream->readSlice(10));
    }

    #[Test]
    public function readSliceReturnsAnEmptyStringWhenTheSliceExpired(): void
    {
        $stream = new ClientStream(ScriptedStream::expiringSlices([ScriptedStream::sliceExpired(), 'late']));

        self::assertSame('', $stream->readSlice(10));
        self::assertSame('late', $stream->readSlice(10));
    }

    /**
     * @return iterable<string, array{ScriptedStream}>
     */
    public static function failedReads(): iterable
    {
        $failure = ScriptedStream::sliceExpired();

        yield 'timed_out missing' => [new ScriptedStream([$failure], [], false)];
        yield 'timed_out false (reset)' => [new ScriptedStream([$failure], ['timed_out' => false], true)];
        yield 'timed_out a truthy non-bool' => [new ScriptedStream([$failure], ['timed_out' => 1], false)];
        yield 'timed_out true but at eof' => [new ScriptedStream([$failure], ['timed_out' => true], true)];
        yield 'metadata throws' => [new ScriptedStream([$failure], new \LogicException('metadata'), false)];
        yield 'eof throws' => [new ScriptedStream([$failure], ['timed_out' => true], new \LogicException('eof'))];
    }

    #[Test]
    #[DataProvider('failedReads')]
    public function readSliceTurnsEveryOtherFailureIntoATransportException(ScriptedStream $inner): void
    {
        $stream = new ClientStream($inner);

        try {
            $stream->readSlice(10);
            self::fail('expected a TransportException');
        } catch (TransportException $e) {
            self::assertNull($e->getPrevious());
            self::assertStringContainsString(\RuntimeException::class, $e->getMessage());
        }
    }

    #[Test]
    public function readStillThrowsOnAnExpiredSlice(): void
    {
        $stream = new ClientStream(ScriptedStream::expiringSlices([ScriptedStream::sliceExpired()]));

        $this->expectException(TransportException::class);
        $stream->read(10);
    }
}
