<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;

/**
 * A single-pass iterator over the mapped rows of a {@see RowSource}, keyed from 0 in file order. The first row is
 * read when iteration starts, not on construction. Like a generator it cannot be rewound once started; unlike one, a
 * failure is thrown by the iterator method that read the row (`rewind()` or `next()`), so it is a checked exception
 * of a plain call.
 *
 * Dropping the iterator before the end (a `break`) closes the body; a failure of that late close is not reported,
 * because nobody is left to receive it.
 *
 * A mapper that returns null skips the row: it is not delivered, the keys stay the file index (so they have gaps) and
 * the path given to the mapper counts it, so an error path always names the row of the file.
 *
 * @template T
 *
 * @implements \Iterator<int, T>
 *
 * @internal returned by {@see \WhiteMarket\Sdk\Export\ExportClient::streamProducts()}
 */
final class RowIterator implements \Iterator
{
    private int $index = -1;

    /** @var T|null */
    private mixed $current = null;

    private bool $valid = false;

    private readonly RowSource $source;

    /**
     * @param \Closure(mixed, string): (T|null) $map the raw row and its path (`products[3]`) to the model, null to skip the row
     */
    public function __construct(
        RowSource $source,
        private readonly \Closure $map,
        private readonly string $path,
    ) {
        $this->source = new GuardedRowSource($source);
    }

    public function __destruct()
    {
        try {
            $this->source->close();
        } catch (TransportException) {
            // Iteration was abandoned; there is no caller to report a failed close to.
        }
    }

    /**
     * @throws \LogicException iteration already started
     * @throws TransportException
     * @throws DecodeException
     */
    public function rewind(): void
    {
        if ($this->index !== -1) {
            throw new \LogicException('The rows of an export are read once: start a new call to read them again.');
        }
        $this->advance();
    }

    /**
     * @throws TransportException
     * @throws DecodeException
     */
    public function next(): void
    {
        if ($this->valid) {
            $this->advance();
        }
    }

    public function valid(): bool
    {
        return $this->valid;
    }

    /**
     * @return T|null
     */
    public function current(): mixed
    {
        return $this->current;
    }

    /**
     * The index of the current row; meaningless once {@see valid()} is false.
     */
    public function key(): int
    {
        return $this->index;
    }

    /**
     * @throws TransportException
     * @throws DecodeException
     */
    private function advance(): void
    {
        $this->valid = false;
        $this->current = null;
        do {
            if (!$this->source->next()) {
                return;
            }
            ++$this->index;
            try {
                $this->current = ($this->map)($this->source->row(), RowPath::of($this->path, $this->index));
            } catch (DecodeException $e) {
                $this->source->close();

                throw $e;
            }
        } while ($this->current === null);
        $this->valid = true;
    }
}
