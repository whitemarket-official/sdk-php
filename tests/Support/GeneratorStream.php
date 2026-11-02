<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use Psr\Http\Message\StreamInterface;

/**
 * A readable, forward-only PSR-7 stream over a generator of chunks, so a very large body is produced as it is read and
 * never held in memory. Its size is unknown.
 */
final class GeneratorStream implements StreamInterface
{
    private string $buffer = '';

    private int $position = 0;

    private bool $closed = false;

    /**
     * @param \Generator<int, string> $chunks
     */
    public function __construct(private readonly \Generator $chunks) {}

    public function __toString(): string
    {
        return $this->getContents();
    }

    public function close(): void
    {
        $this->closed = true;
        $this->buffer = '';
    }

    public function detach(): null
    {
        $this->close();

        return null;
    }

    public function getSize(): null
    {
        return null;
    }

    public function tell(): int
    {
        return $this->position;
    }

    public function eof(): bool
    {
        return $this->closed || ($this->buffer === '' && !$this->chunks->valid());
    }

    public function isSeekable(): bool
    {
        return false;
    }

    public function seek(int $offset, int $whence = \SEEK_SET): never
    {
        throw new \RuntimeException('The stream is not seekable.');
    }

    public function rewind(): never
    {
        throw new \RuntimeException('The stream is not seekable.');
    }

    public function isWritable(): bool
    {
        return false;
    }

    public function write(string $string): never
    {
        throw new \RuntimeException('The stream is not writable.');
    }

    public function isReadable(): bool
    {
        return !$this->closed;
    }

    public function read(int $length): string
    {
        while ($this->buffer === '' && !$this->closed && $this->chunks->valid()) {
            $this->buffer = $this->chunks->current();
            $this->chunks->next();
        }
        $out = substr($this->buffer, 0, $length);
        $this->buffer = substr($this->buffer, $length);
        $this->position += \strlen($out);

        return $out;
    }

    public function getContents(): string
    {
        $all = '';
        while (!$this->eof()) {
            $all .= $this->read(8192);
        }

        return $all;
    }

    public function getMetadata(?string $key = null): mixed
    {
        return $key === null ? [] : null;
    }
}
