<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use JsonMachine\Exception\JsonMachineException;
use JsonMachine\Items;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Transport\BodyReader;

/**
 * The elements of a JSON array body through `halaxa/json-machine` (ADR 0004), each decoded with
 * `JSON_BIGINT_AS_STRING` (an integer beyond the platform int arrives as a digit string). json-machine stops at the
 * end of the array: bytes after it (`[1]x`) are not looked at; that the body is complete is checked by the reader
 * (Content-Length, the gzip end, SDK_SPEC 3.10 rule 7).
 *
 * @internal
 */
final class JsonMachineRows implements RowSource
{
    /** @var \Iterator<mixed, mixed>|null */
    private ?\Iterator $items = null;

    private int $index = 0;

    private ?JsonArrayChunks $chunks = null;

    private mixed $row = null;

    public function __construct(
        private readonly BodyReader $reader,
        private readonly string $path,
    ) {}

    public function next(): bool
    {
        try {
            return $this->advance();
        } catch (JsonMachineException) {
            // Its message quotes the body, so it is not chained (no server bytes in messages).
            $invalid = RowPath::invalidJson($this->path, $this->index);
        }

        throw $invalid;
    }

    /**
     * @throws JsonMachineException
     * @throws DecodeException
     * @throws TransportException
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
            return false;
        }
        $current = $this->items->current();
        $this->row = $current instanceof TakeOnceRow ? $current->take() : $current;
        $this->chunks?->rowRead($this->index + 1);

        return true;
    }

    public function row(): mixed
    {
        return $this->row;
    }

    public function close(): void
    {
        $this->reader->close();
    }

    /**
     * @return \Iterator<mixed, mixed>
     */
    private function open(): \Iterator
    {
        $this->chunks = new JsonArrayChunks($this->reader, $this->path);
        $items = Items::fromIterable(
            $this->chunks,
            ['decoder' => new TakeOnceDecoder()],
        );

        return new \IteratorIterator($items);
    }
}
