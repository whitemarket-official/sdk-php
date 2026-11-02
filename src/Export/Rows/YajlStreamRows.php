<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Transport\BodyReader;
use YajlStream\ArrayParser;
use YajlStream\ParseException;

/**
 * The elements of a JSON array body through `ext-yajl_stream` (`YajlStream\ArrayParser`, a libyajl SAX parser in C;
 * ADR 0006), reading the body through {@see BodyStreamWrapper}.
 *
 * Unlike the other decoders, the extension turns an integer beyond the 64-bit range into a float: such an id fails as
 * a {@see DecodeException} in the mapper (a float id is refused, never rounded). Bytes after the end of the array are
 * not looked at, as with json-machine. A failure of the body (a {@see \WhiteMarket\Sdk\Errors\TransportException},
 * the array check) is the original exception, not the parser's.
 *
 * @internal
 */
final class YajlStreamRows implements RowSource
{
    private ?string $url = null;

    private ?JsonArrayChunks $chunks = null;

    /** @var \Iterator<mixed, mixed>|null */
    private ?\Iterator $items = null;

    private int $index = 0;

    private mixed $row = null;

    public function __construct(
        private readonly BodyReader $reader,
        private readonly string $path,
    ) {}

    public function next(): bool
    {
        try {
            return $this->advance();
        } catch (ParseException) {
            // A failure of the body is the original exception; otherwise the parser's message quotes the body, so
            // it is not chained (no server bytes in messages).
            $failure = $this->release() ?? RowPath::invalidJson($this->path, $this->index);
        }

        throw $failure;
    }

    /**
     * @throws \Throwable the parser's ParseException, or a failure of the body kept by the stream wrapper
     */
    private function advance(): bool
    {
        if (!$this->items instanceof \Iterator) {
            $this->items = $this->open();
            $this->items->rewind();
        } else {
            ++$this->index;
            $this->row = null; // the previous row is dropped before the next is decoded
            $this->items->next();
        }
        if (!$this->items->valid()) {
            $this->finish();

            return false;
        }
        $this->row = $this->items->current();
        $this->chunks?->rowRead($this->index + 1);

        return true;
    }

    /**
     * @return \Iterator<mixed, mixed>
     */
    private function open(): \Iterator
    {
        $this->chunks = new JsonArrayChunks($this->reader, $this->path);
        $this->url = BodyStreamWrapper::register($this->chunks);

        return (new ArrayParser($this->url))->getIterator();
    }

    /**
     * The end of the body: a failure of the body kept by the stream wrapper is thrown, otherwise the rest of the body
     * is read.
     *
     * @throws \Throwable
     */
    private function finish(): void
    {
        $failure = $this->release();
        if ($failure instanceof \Throwable) {
            throw $failure;
        }
        $this->drain();
    }

    public function row(): mixed
    {
        return $this->row;
    }

    /**
     * libyajl stops reading at the closing `]`, so the reader would never reach its end checks (Content-Length, the
     * gzip end marker, data after the gzip stream): the rest of the body is read here, which runs them. Its bytes are
     * not looked at (DEVIATIONS, "Accepted limitations").
     *
     * @throws TransportException
     */
    private function drain(): void
    {
        $chunks = $this->chunks;
        if (!$chunks instanceof JsonArrayChunks || !$chunks->valid()) {
            return;
        }
        do {
            $chunks->next();
        } while ($chunks->valid());
    }

    public function close(): void
    {
        $this->release();
        $this->items = null;
        $this->reader->close();
    }

    private function release(): ?\Throwable
    {
        if ($this->url === null) {
            return null;
        }
        $failure = BodyStreamWrapper::release($this->url);
        $this->url = null;

        return $failure;
    }
}
