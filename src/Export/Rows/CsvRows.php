<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Transport\BodyReader;

/**
 * The records of a CSV body (RFC 4180: `,`, `"` quoting with `""` inside, CRLF or LF line ends, a UTF-8 BOM ignored)
 * as maps from the header names to the values: an empty value is null, an empty line is skipped. A line end inside a
 * quoted field belongs to the field: a record ends at a line feed after an even number of quotes.
 *
 * A line end is a line feed, with the carriage return directly before it dropped; any other carriage return is data
 * (a last record without a line feed keeps all its bytes).
 *
 * Failures ({@see DecodeException}): no header, an empty or repeated column name (all at the list path), a record
 * whose field count differs from the header, a record longer than {@see RowSource::MAX_ROW_BYTES}, or a body that ends
 * inside a quoted field (at the row, `products[3]`).
 *
 * @internal
 */
final class CsvRows implements RowSource
{
    private const UTF8_BOM = "\xEF\xBB\xBF";

    private string $buffer = '';

    /** Offset of the first byte of the record being looked for. */
    private int $start = 0;

    /** Offset up to which the buffer has been scanned. */
    private int $scan = 0;

    private bool $quoted = false;

    private bool $first = true;

    private bool $ended = false;

    /** @var list<string>|null */
    private ?array $header = null;

    private int $index = -1;

    /** @var array<string, string|null> */
    private array $row = [];

    public function __construct(
        private readonly BodyReader $reader,
        private readonly string $path,
    ) {}

    public function next(): bool
    {
        $this->row = []; // the previous row is dropped before the next is decoded
        while (($record = $this->nextRecord()) !== null) {
            if ($record === '') {
                continue;
            }
            $fields = str_getcsv($record, ',', '"', '');
            if ($this->header === null) {
                $this->header = $this->header($fields, $this->path);

                continue;
            }
            ++$this->index;
            $this->row = $this->combine($fields);

            return true;
        }
        if ($this->header === null) {
            throw new DecodeException($this->path, 'no CSV header');
        }

        return false;
    }

    /**
     * @return array<string, string|null>
     */
    public function row(): array
    {
        return $this->row;
    }

    public function close(): void
    {
        $this->reader->close();
    }

    /**
     * The next record without its line end, null after the last.
     *
     * @throws DecodeException
     * @throws TransportException
     */
    private function nextRecord(): ?string
    {
        while (true) {
            $length = \strlen($this->buffer);
            while (($this->scan += strcspn($this->buffer, "\"\n", $this->scan)) < $length) {
                $at = $this->scan++;
                if ($this->buffer[$at] === '"') {
                    $this->quoted = !$this->quoted;
                } elseif (!$this->quoted) {
                    // Like refill(), the length excludes the line end (CR LF or LF); a step that holds the whole record is
                    // not checked there.
                    $cr = $at > $this->start && $this->buffer[$at - 1] === "\r" ? 1 : 0;
                    $length = $at - $this->start - $cr;
                    if ($length > RowSource::MAX_ROW_BYTES) {
                        throw new DecodeException($this->recordPath(), 'a CSV record is too long');
                    }
                    $record = substr($this->buffer, $this->start, $length);
                    $this->start = $at + 1;

                    return $record;
                }
            }
            if ($this->ended) {
                return $this->lastRecord();
            }
            $this->refill();
        }
    }

    /**
     * Where a failure of the record being read is reported: the row it would be (`products[3]`), or the list itself
     * while the header is read.
     */
    private function recordPath(): string
    {
        return $this->header === null ? $this->path : RowPath::of($this->path, $this->index + 1);
    }

    /**
     * Drops the records already returned (once per chunk, not per record), then appends the next chunk.
     *
     * @throws DecodeException
     * @throws TransportException
     */
    private function refill(): void
    {
        $this->buffer = substr($this->buffer, $this->start);
        $this->scan -= $this->start;
        $this->start = 0;
        // One byte more than a record: a CR waiting for its line feed.
        if (\strlen($this->buffer) > RowSource::MAX_ROW_BYTES + 1) {
            throw new DecodeException($this->recordPath(), 'a CSV record is too long');
        }
        $chunk = $this->reader->next();
        if ($chunk === null) {
            $this->ended = true;

            return;
        }
        $this->buffer .= $chunk;
        if ($this->first) {
            $this->skipBom();
        }
    }

    /**
     * Strips the BOM once the start of the body shows whether there is one: with fewer than 3 bytes that still can
     * begin a BOM, the decision waits for the next chunk (the BOM may be cut by a chunk boundary).
     */
    private function skipBom(): void
    {
        if (\strlen($this->buffer) < \strlen(self::UTF8_BOM) && str_starts_with(self::UTF8_BOM, $this->buffer)) {
            return;
        }
        $this->first = false;
        if (str_starts_with($this->buffer, self::UTF8_BOM)) {
            $this->buffer = substr($this->buffer, \strlen(self::UTF8_BOM));
            $this->scan = 0; // only BOM bytes were scanned so far
        }
    }

    /**
     * @throws DecodeException
     */
    private function lastRecord(): ?string
    {
        if ($this->quoted) {
            throw new DecodeException($this->recordPath(), 'the body ends inside a quoted CSV field');
        }
        $record = substr($this->buffer, $this->start);
        $this->buffer = '';
        $this->start = 0;
        $this->scan = 0;
        if ($record === '') {
            return null;
        }
        if (\strlen($record) > RowSource::MAX_ROW_BYTES) {
            throw new DecodeException($this->recordPath(), 'a CSV record is too long');
        }

        return $record;
    }

    /**
     * @param list<string|null> $fields
     *
     * @return array<string, string|null>
     *
     * @throws DecodeException
     */
    private function combine(#[\SensitiveParameter] array $fields): array
    {
        $header = $this->header ?? [];
        if (\count($fields) !== \count($header)) {
            throw new DecodeException(RowPath::of($this->path, $this->index), 'the field count differs from the header');
        }
        $row = [];
        foreach (array_combine($header, $fields) as $name => $value) {
            $row[$name] = $value === '' ? null : $value;
        }

        return $row;
    }

    /**
     * @param list<string|null> $fields
     *
     * @return list<string>
     *
     * @throws DecodeException
     */
    private function header(#[\SensitiveParameter] array $fields, string $path): array
    {
        $names = [];
        foreach ($fields as $name) {
            $names[] = (string) $name;
        }
        if (\in_array('', $names, true) || \count(array_unique($names)) !== \count($names)) {
            throw new DecodeException($path, 'the CSV header has an empty or repeated column name');
        }

        return $names;
    }
}
