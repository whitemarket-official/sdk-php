<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Transport\BodyReader;

/**
 * The decoded chunks of a body that must hold a JSON array, as an iterator for a parser that pulls chunks
 * (json-machine, the `yajl_stream` stream wrapper). Before the first chunk is handed on, the first byte that is not
 * white space must open an array: json-machine iterates a top-level object and ends quietly on a scalar, and
 * `yajl_stream` yields nothing for either, none of which is a list of rows. An empty body is not one either.
 *
 * Both parsers keep an unfinished element in memory, so one row is capped here: the bytes handed out since the last
 * {@see rowRead()} may not exceed {@see RowSource::MAX_ROW_BYTES} plus two slices. A reader step can be as large as
 * {@see BodyReader::MAX_STEP_BYTES}, and a parser that was given a whole step holds its unparsed rest, which belongs
 * to the next row and is never counted; so the parser is handed slices of at most {@see SLICE_BYTES}, and the
 * allowance is two of them: the unparsed rest of the slice in which the previous row ended, and the slice in which
 * the check fires. A row up to the limit is never refused; one beyond the limit plus three slices always is. The
 * step itself is held once, by this class. The parser does not say where a row ends, so its caller marks each row it
 * has received.
 *
 * @implements \Iterator<int, string>
 *
 * @internal
 */
final class JsonArrayChunks implements \Iterator
{
    /** The most bytes the parser is handed at once. */
    public const SLICE_BYTES = 8192;

    private int $key = -1;

    /** The reader step the slices are cut from. */
    private ?string $step = null;

    /** Offset in {@see $step} of the next slice. */
    private int $offset = 0;

    /** The slice handed out now; null at the end of the body. */
    private ?string $chunk = null;

    /** Bytes handed out since the last {@see rowRead()}. */
    private int $rowBytes = 0;

    /** Index of the row being read, for the path of the failure. */
    private int $rowIndex = 0;

    public function __construct(
        private readonly BodyReader $reader,
        private readonly string $path,
    ) {}

    /**
     * A row was received: the next chunks belong to the row at `$nextIndex`.
     */
    public function rowRead(int $nextIndex): void
    {
        $this->rowBytes = 0;
        $this->rowIndex = $nextIndex;
    }

    /**
     * @throws \LogicException already started
     * @throws TransportException
     * @throws DecodeException
     */
    public function rewind(): void
    {
        if ($this->key !== -1) {
            throw new \LogicException('The body is read once.');
        }
        $this->key = 0;
        while (($chunk = $this->reader->next()) !== null) {
            $start = ltrim($chunk, JsonArrayScanner::WHITE_SPACE);
            if ($start === '') {
                continue;
            }
            if ($start[0] !== '[') {
                throw RowPath::notAList($this->path);
            }
            $this->step = $chunk;
            $this->offset = 0;
            $this->takeSlice();

            return;
        }

        throw RowPath::notAList($this->path);
    }

    /**
     * @throws TransportException
     * @throws DecodeException
     */
    public function next(): void
    {
        if ($this->chunk === null) {
            return;
        }
        ++$this->key;
        if ($this->step === null || $this->offset >= \strlen($this->step)) {
            $this->step = $this->reader->next();
            $this->offset = 0;
        }
        $this->takeSlice();
    }

    /**
     * Makes the next slice of the step the current chunk and counts it against the row.
     *
     * @throws DecodeException
     */
    private function takeSlice(): void
    {
        if ($this->step === null) {
            $this->chunk = null;

            return;
        }
        $slice = substr($this->step, $this->offset, self::SLICE_BYTES);
        $this->offset += \strlen($slice);
        $this->chunk = $slice;
        $this->rowBytes += \strlen($slice);
        if ($this->rowBytes > RowSource::MAX_ROW_BYTES + 2 * self::SLICE_BYTES) {
            throw RowPath::elementTooLong($this->path, $this->rowIndex);
        }
    }

    public function valid(): bool
    {
        return $this->chunk !== null;
    }

    public function current(): string
    {
        return $this->chunk ?? '';
    }

    public function key(): int
    {
        return $this->key;
    }
}
