<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export\Rows;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Export\Rows\BodyStreamWrapper;
use WhiteMarket\Sdk\Export\Rows\JsonArrayChunks;
use WhiteMarket\Sdk\Tests\Support\RowSources;

/**
 * The stream wrapper is exercised through plain `fopen()` on its URL, as the yajl parser does; no extension needed.
 */
#[CoversClass(BodyStreamWrapper::class)]
#[CoversClass(JsonArrayChunks::class)]
final class BodyStreamWrapperTest extends TestCase
{
    #[Test]
    public function readsTheWholeBodyThroughTheUrl(): void
    {
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::chunked('[{"a":1},{"a":2}]', 4), 'products'));

        $body = $this->readAll($url, 8192);

        self::assertSame('[{"a":1},{"a":2}]', $body);
        self::assertNull(BodyStreamWrapper::release($url));
    }

    /**
     * @return iterable<string, array{positive-int}>
     */
    public static function readSizes(): iterable
    {
        yield '1 byte at a time' => [1];
        yield '3 bytes, across chunk boundaries' => [3];
        yield 'exactly the chunk size' => [5];
        yield 'more than a chunk' => [64];
    }

    /**
     * @param positive-int $size
     */
    #[Test]
    #[DataProvider('readSizes')]
    public function giveTheSameBytesWhateverTheSizeOfTheReads(int $size): void
    {
        $body = ' [1,"é",{"k":[2,3]}] ';
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::chunked($body, 5), 'products'));

        self::assertSame($body, $this->readAll($url, $size));
        BodyStreamWrapper::release($url);
    }

    #[Test]
    public function aBodyOfSeveralChunksIsReadWhole(): void
    {
        $body = '[' . implode(',', range(1, 20000)) . ']';
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader($body), 'products'));

        self::assertSame($body, $this->readAll($url, 4096));
        BodyStreamWrapper::release($url);
    }

    #[Test]
    public function everyRegistrationGetsItsOwnUrl(): void
    {
        $first = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('[1]'), 'products'));
        $second = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('[2]'), 'products'));

        self::assertNotSame($first, $second);
        self::assertStringStartsWith(BodyStreamWrapper::SCHEME . '://', $first);
        self::assertSame('[2]', $this->readAll($second, 100));
        self::assertSame('[1]', $this->readAll($first, 100));
        BodyStreamWrapper::release($first);
        BodyStreamWrapper::release($second);
    }

    #[Test]
    public function anUnknownIdCannotBeOpened(): void
    {
        BodyStreamWrapper::release(BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('[1]'), 'products')));

        $handle = $this->open(BodyStreamWrapper::SCHEME . '://0123456789abcdef0123456789abcdef', 'rb');

        self::assertFalse($handle);
    }

    #[Test]
    public function aReleasedBodyCannotBeOpenedAgain(): void
    {
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('[1]'), 'products'));
        self::assertSame('[1]', $this->readAll($url, 100));

        BodyStreamWrapper::release($url);

        self::assertFalse($this->open($url, 'rb'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function modes(): iterable
    {
        yield 'read' => ['r', true];
        yield 'read binary' => ['rb', true];
        yield 'write' => ['w', false];
        yield 'append' => ['a', false];
        yield 'read and write' => ['r+', false];
        yield 'exclusive create' => ['x', false];
    }

    #[Test]
    #[DataProvider('modes')]
    public function onlyReadModesOpen(string $mode, bool $opens): void
    {
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('[1]'), 'products'));

        $handle = $this->open($url, $mode);

        self::assertSame($opens, $handle !== false);
        if (\is_resource($handle)) {
            fclose($handle);
        }
        BodyStreamWrapper::release($url);
    }

    #[Test]
    public function aRefusedWriteModeDoesNotConsumeTheBody(): void
    {
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('[1]'), 'products'));
        self::assertFalse($this->open($url, 'w'));

        self::assertSame('[1]', $this->readAll($url, 100));
        BodyStreamWrapper::release($url);
    }

    #[Test]
    public function aFailureOfTheBodyEndsTheReadAndIsReturnedByRelease(): void
    {
        $body = '[1,2]';
        $reader = RowSources::reader($body, ['Content-Length' => '1000']);
        $url = BodyStreamWrapper::register(new JsonArrayChunks($reader, 'products'));

        $read = $this->readAll($url, 100);
        $failure = BodyStreamWrapper::release($url);

        self::assertSame('[1,2]', $read, 'what arrived before the failure is delivered');
        self::assertInstanceOf(TransportException::class, $failure);
    }

    #[Test]
    public function aBodyThatIsNotAnArrayDeliversNothingAndReleaseReturnsTheDecodeException(): void
    {
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('{"a":1}'), 'products'));

        $read = $this->readAll($url, 100);
        $failure = BodyStreamWrapper::release($url);

        self::assertSame('', $read);
        self::assertInstanceOf(DecodeException::class, $failure);
        self::assertSame('products', $failure->path);
    }

    #[Test]
    public function anEmptyBodyIsNotAListEither(): void
    {
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader(''), 'products'));

        $this->readAll($url, 100);

        self::assertInstanceOf(DecodeException::class, BodyStreamWrapper::release($url));
    }

    #[Test]
    public function theFailureIsHandedBackOnce(): void
    {
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('5'), 'products'));
        $this->readAll($url, 100);

        self::assertInstanceOf(DecodeException::class, BodyStreamWrapper::release($url));
        self::assertNull(BodyStreamWrapper::release($url));
    }

    #[Test]
    public function aFailureIsKeptPerBodyNotShared(): void
    {
        $bad = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('5'), 'products'));
        $good = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('[1]'), 'products'));
        $this->readAll($bad, 100);
        $this->readAll($good, 100);

        self::assertNull(BodyStreamWrapper::release($good));
        self::assertInstanceOf(DecodeException::class, BodyStreamWrapper::release($bad));
    }

    #[Test]
    public function releasingAnUnknownUrlReturnsNoFailure(): void
    {
        self::assertNull(BodyStreamWrapper::release(BodyStreamWrapper::SCHEME . '://ffffffffffffffffffffffffffffffff'));
    }

    #[Test]
    public function reportsEndOfFileOnlyAfterTheLastByteWasRead(): void
    {
        $url = BodyStreamWrapper::register(new JsonArrayChunks(RowSources::reader('[1]'), 'products'));
        $handle = $this->open($url, 'rb');
        self::assertIsResource($handle);

        self::assertSame('[1', fread($handle, 2));
        self::assertFalse(feof($handle));
        self::assertSame(']', fread($handle, 2));
        self::assertSame('', fread($handle, 2));
        self::assertTrue(feof($handle));
        fclose($handle);
        BodyStreamWrapper::release($url);
    }

    /**
     * @return resource|false
     */
    private function open(string $url, string $mode): mixed
    {
        set_error_handler(static fn(): bool => true);
        try {
            return fopen($url, $mode);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param positive-int $size
     */
    private function readAll(string $url, int $size): string
    {
        $handle = $this->open($url, 'rb');
        self::assertIsResource($handle);
        $body = '';
        while (!feof($handle)) {
            $chunk = fread($handle, $size);
            if ($chunk === false || ($chunk === '' && feof($handle))) {
                break;
            }
            $body .= $chunk;
        }
        fclose($handle);

        return $body;
    }
}
