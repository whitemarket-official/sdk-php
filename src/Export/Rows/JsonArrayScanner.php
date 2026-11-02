<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Transport\BodyReader;

/**
 * Cuts the elements of a top-level JSON array out of a streamed body as raw JSON text, without decoding them, so a
 * fast decoder can take many at once ({@see BatchJsonRows}). It tracks strings (with their escapes) and the nesting
 * depth: a `,` or the closing `]` at depth 1 ends an element. The structural bytes are ASCII, so scanning bytes is
 * safe for UTF-8. Whether an element is valid JSON is the decoder's to say; the scanner checks the frame:
 *
 * - the first byte that is not white space opens an array, and an empty body is not a list ({@see DecodeException}
 *   at the path);
 * - an empty element (`[1,,2]`, `[1,]`) or a `}` closing the array is invalid at the index of that element;
 * - a body that ends before the array closes is cut short, at the index of the element being read;
 * - after the closing `]` only white space may follow, up to the end of the body;
 * - an element longer than {@see RowSource::MAX_ROW_BYTES} is refused, so one element bounds the memory.
 *
 * @internal
 */
final class JsonArrayScanner
{
    public const WHITE_SPACE = " \t\n\r";

    private string $buffer = '';

    /** Offset of the first byte of the element being read. */
    private int $start = 0;

    /** Offset up to which the buffer has been scanned (may pass the end by one after a backslash). */
    private int $scan = 0;

    private int $depth = 0;

    private bool $inString = false;

    private bool $opened = false;

    private bool $closed = false;

    private bool $ended = false;

    /** Elements returned so far: the index of the next one. */
    private int $nextIndex = 0;

    public function __construct(
        private readonly BodyReader $reader,
        private readonly string $path,
    ) {}

    /**
     * The index the next element returned by {@see next()} has.
     */
    public function index(): int
    {
        return $this->nextIndex;
    }

    /**
     * The raw text of the next element, white space trimmed; null after the last one, once the rest of the body
     * was checked to be white space.
     *
     * @throws DecodeException
     * @throws TransportException
     */
    public function next(): ?string
    {
        while (true) {
            if ($this->closed) {
                $this->assertOnlyWhiteSpaceRemains();

                return null;
            }
            $element = $this->scanBuffer();
            if ($element !== null) {
                return $element;
            }
            $this->awaitMore();
        }
    }

    /**
     * The buffer holds no complete element: once the array is closed there is nothing to wait for ({@see next()} then
     * checks the rest of the body), otherwise the body must still have a chunk.
     *
     * @throws DecodeException
     * @throws TransportException
     */
    private function awaitMore(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->ended) {
            throw new DecodeException($this->elementPath(), 'the list is cut short');
        }
        $this->refill();
    }

    /**
     * @throws DecodeException
     */
    private function scanBuffer(): ?string
    {
        $length = \strlen($this->buffer);
        if (!$this->opened && !$this->open($length)) {
            return null;
        }
        while ($this->scan < $length) {
            if ($this->inString) {
                $this->scan += strcspn($this->buffer, '"\\', $this->scan);
                if ($this->scan >= $length) {
                    return null;
                }
                if ($this->buffer[$this->scan] === '\\') {
                    $this->scan += 2;

                    continue;
                }
                $this->inString = false;
                ++$this->scan;

                continue;
            }
            $this->scan += strcspn($this->buffer, '"[]{},', $this->scan);
            if ($this->scan >= $length) {
                return null;
            }
            $element = $this->structural($this->buffer[$this->scan++]);
            if ($element !== null || $this->closed) {
                return $element;
            }
        }

        return null;
    }

    /**
     * Skips the white space before the array and consumes its `[`; false while only white space has arrived.
     *
     * @throws DecodeException
     */
    private function open(int $length): bool
    {
        $this->scan += strspn($this->buffer, self::WHITE_SPACE, $this->scan);
        if ($this->scan >= $length) {
            return false;
        }
        if ($this->buffer[$this->scan] !== '[') {
            throw RowPath::notAList($this->path);
        }
        $this->opened = true;
        $this->depth = 1;
        $this->start = ++$this->scan;

        return true;
    }

    /**
     * Handles one structural byte; returns the element it ends, if any.
     *
     * @throws DecodeException
     */
    private function structural(#[\SensitiveParameter] string $byte): ?string
    {
        switch ($byte) {
            case '"':
                $this->inString = true;

                return null;
            case '[':
            case '{':
                ++$this->depth;

                return null;
            case ',':
                return $this->depth === 1 ? $this->cut(allowEmpty: false) : null;
            default: // ']' or '}'
                if ($this->depth > 1) {
                    --$this->depth;

                    return null;
                }
                if ($byte === '}') {
                    throw RowPath::invalidJson($this->path, $this->nextIndex);
                }
                $this->closed = true;

                return $this->cut(allowEmpty: $this->nextIndex === 0);
        }
    }

    /**
     * The element from its start to the separator just scanned. Only `[]` (and `[ ]`) may have an empty one.
     *
     * @throws DecodeException
     */
    private function cut(bool $allowEmpty): ?string
    {
        $element = trim(substr($this->buffer, $this->start, $this->scan - 1 - $this->start), self::WHITE_SPACE);
        $this->start = $this->scan;
        if (\strlen($element) > RowSource::MAX_ROW_BYTES) { // a step that holds the whole element is not checked by refill()
            throw RowPath::elementTooLong($this->path, $this->nextIndex);
        }
        if ($element === '') {
            return $allowEmpty ? null : throw RowPath::invalidJson($this->path, $this->nextIndex);
        }
        ++$this->nextIndex;

        return $element;
    }

    /**
     * Drops what was already returned (once per chunk, not per element), then appends the next chunk.
     *
     * @throws DecodeException
     * @throws TransportException
     */
    private function refill(): void
    {
        $keepFrom = $this->opened ? $this->start : $this->scan;
        $this->buffer = substr($this->buffer, $keepFrom);
        $this->scan -= $keepFrom;
        $this->start = 0;
        if (\strlen($this->buffer) > RowSource::MAX_ROW_BYTES) {
            throw RowPath::elementTooLong($this->path, $this->nextIndex);
        }
        $chunk = $this->reader->next();
        if ($chunk === null) {
            $this->ended = true;
            if (!$this->opened) {
                throw RowPath::notAList($this->path);
            }

            return;
        }
        $this->buffer .= $chunk;
    }

    /**
     * @throws DecodeException
     * @throws TransportException
     */
    private function assertOnlyWhiteSpaceRemains(): void
    {
        $rest = substr($this->buffer, $this->scan);
        $this->buffer = '';
        $this->scan = 0;
        while (true) {
            if (strspn($rest, self::WHITE_SPACE) !== \strlen($rest)) {
                throw new DecodeException($this->path, 'data after the end of the list');
            }
            if ($this->ended) {
                return;
            }
            $chunk = $this->reader->next();
            if ($chunk === null) {
                $this->ended = true;

                return;
            }
            $rest = $chunk;
        }
    }

    private function elementPath(): string
    {
        return RowPath::of($this->path, $this->nextIndex);
    }
}
