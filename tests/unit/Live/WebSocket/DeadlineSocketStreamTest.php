<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live\WebSocket;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Live\WebSocket\DeadlineExceeded;
use WhiteMarket\Sdk\Live\WebSocket\DeadlineSocketStream;

#[CoversClass(DeadlineSocketStream::class)]
#[CoversClass(DeadlineExceeded::class)]
final class DeadlineSocketStreamTest extends TestCase
{
    /** @var resource|null */
    private $peer;

    private ?DeadlineSocketStream $stream = null;

    protected function setUp(): void
    {
        $pair = stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);
        self::assertIsArray($pair);
        $own = $pair[0] ?? throw new \LogicException('no socket');
        $peer = $pair[1] ?? throw new \LogicException('no socket');
        $this->stream = new DeadlineSocketStream($own);
        $this->peer = $peer;
    }

    protected function tearDown(): void
    {
        $this->stream?->close();
        if (\is_resource($this->peer)) {
            fclose($this->peer);
        }
    }

    private function stream(): DeadlineSocketStream
    {
        return $this->stream ?? throw new \LogicException('no stream');
    }

    /**
     * @return resource
     */
    private function peer()
    {
        return $this->peer ?? throw new \LogicException('no peer');
    }

    #[Test]
    public function aWriteThatFitsIsCompleteAndLeavesTheStreamBlocking(): void
    {
        $this->stream()->setDeadline(microtime(true) + 2);

        self::assertSame(5, $this->stream()->write('hello'));
        self::assertSame('hello', fread($this->peer(), 5));
        self::assertTrue($this->stream()->isBlocking());
    }

    #[Test]
    public function aWriteToAPeerThatNeverReadsFailsAtTheDeadline(): void
    {
        $this->stream()->setDeadline(microtime(true) + 0.5);
        $start = microtime(true);

        try {
            $this->stream()->write(str_repeat('a', 20_000_000));
            self::fail('expected DeadlineExceeded');
        } catch (DeadlineExceeded) {
            self::assertLessThan(2.0, microtime(true) - $start);
        }
    }

    #[Test]
    public function aReadAfterTheDeadlineFails(): void
    {
        $this->stream()->setDeadline(microtime(true) - 1);

        $this->expectException(DeadlineExceeded::class);
        $this->stream()->read(4);
    }

    #[Test]
    public function aLoneZeroByteIsExtendedWhenMoreThanOneByteWasAsked(): void
    {
        // A child writes "0", pauses, then "5": the first read gets the bare "0" phrity would call an empty read.
        $process = proc_open([\PHP_BINARY, '-r', 'fwrite(STDOUT, "0"); fflush(STDOUT); usleep(300000); fwrite(STDOUT, "5");'], [1 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $out = $pipes[1] ?? throw new \LogicException('no pipe');

        try {
            $stream = new DeadlineSocketStream($out);
            $stream->setDeadline(microtime(true) + 5);

            self::assertSame('05', $stream->read(4));
        } finally {
            fclose($out);
            proc_close($process);
        }
    }

    #[Test]
    public function aLoneZeroByteIsReturnedWhenExactlyOneByteWasAsked(): void
    {
        fwrite($this->peer(), '0');
        $this->stream()->setDeadline(microtime(true) + 2);

        self::assertSame('0', $this->stream()->read(1));
    }
}
