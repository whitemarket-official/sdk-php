<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live\WebSocket;

use Phrity\Net\SocketStream;
use Phrity\Net\StreamException;

/**
 * A socket stream whose reads stop at an absolute deadline. phrity reads a frame (and the upgrade response) with one
 * socket timeout per read, so a server sending a byte just inside that timeout would hold a read for payload length
 * times the gap; checking a deadline before every read bounds the whole operation. Writes are bounded the same way:
 * the socket timeout does not cover `fwrite()`, so a peer that never reads would block it for good; a write waits for
 * the socket to be writable until the deadline and sends what fits without blocking.
 *
 * A read that returns exactly "0" is extended by one more read when more than one byte was asked for: phrity's
 * `FrameHandler::read()` treats it as an empty read (`empty("0")`) and fails the connection (docs/DEVIATIONS.md).
 *
 * @internal
 */
final class DeadlineSocketStream extends SocketStream
{
    private ?float $deadline = null;

    /**
     * @param ?float $deadline microtime(true) after which reads fail; null for none
     */
    public function setDeadline(?float $deadline): void
    {
        $this->deadline = $deadline;
    }

    /**
     * @phpstan-param int $length widened to the PSR-7 signature; phrity narrows it to int<1, max>
     */
    public function read(int $length): string
    {
        $this->assertWithinDeadline();
        $length = max(1, $length);
        $data = parent::read($length);
        while ($data === '0' && $length > 1) {
            $this->assertWithinDeadline();
            $more = parent::read($length - 1);
            if ($more === '') {
                break;
            }
            $data .= $more;
        }

        return $data;
    }

    public function write(string $string): int
    {
        $resource = $this->getResource();
        if (!\is_resource($resource)) {
            throw new StreamException(StreamException::FAIL_WRITE);
        }
        $total = \strlen($string);
        $written = 0;
        $blocking = $this->isBlocking();
        stream_set_blocking($resource, false);

        try {
            while ($written < $total) {
                $this->awaitWritable($resource);
                $count = @fwrite($resource, substr($string, $written));
                if ($count === false) {
                    throw new StreamException(StreamException::FAIL_WRITE);
                }
                $written += $count;
            }
        } finally {
            if (\is_resource($resource)) {
                stream_set_blocking($resource, $blocking ?? true);
            }
        }

        return $written;
    }

    /**
     * @param int<0, max> $length
     */
    public function readLine(int $length): ?string
    {
        $this->assertWithinDeadline();

        // fgets() blocks until a newline arrives, so one call would span a whole trickled line. phrity's HTTP reader calls
        // again until it has the newline, so one byte per call keeps the deadline check between bytes.
        return parent::readLine(max(0, min($length, 2)));
    }

    /**
     * @param resource $resource
     */
    private function awaitWritable($resource): void
    {
        while (true) {
            $remaining = $this->deadline === null ? 1.0 : $this->deadline - microtime(true);
            if ($remaining <= 0.0) {
                throw new DeadlineExceeded('The live socket write passed its deadline.');
            }
            $remaining = min($remaining, 1.0);
            $seconds = (int) floor($remaining);
            $read = null;
            $write = [$resource];
            $except = null;
            // false: a broken socket or a signal; the next fwrite() reports a real failure.
            $ready = @stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000));
            if ($ready === false) {
                throw new StreamException(StreamException::FAIL_WRITE);
            }
            if ($ready > 0) {
                return;
            }
        }
    }

    private function assertWithinDeadline(): void
    {
        if ($this->deadline !== null && microtime(true) > $this->deadline) {
            throw new DeadlineExceeded('The live socket read passed its deadline.');
        }
    }
}
