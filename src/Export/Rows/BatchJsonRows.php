<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Transport\BodyReader;

/**
 * The elements of a JSON array body, cut out by {@see JsonArrayScanner} and decoded in batches: up to
 * {@see BATCH_ROWS} elements (or {@see BATCH_BYTES} of text) joined into one array and decoded by one call (ADR 0006).
 *
 * With `ext-simdjson` a batch goes to `simdjson_decode()`, unless its text holds a run of 19 or more digits: simdjson
 * 4.0.0 returns an integer above `PHP_INT_MAX` as a float, silently [observed 2026-11-01], so such a batch (and one
 * simdjson refuses) is decoded by `json_decode()` with `JSON_BIGINT_AS_STRING` instead. The rows are the same as
 * without the extension, an id above `PHP_INT_MAX` included; a run of digits inside a string only costs the faster
 * path. A batch `json_decode()` refuses is checked element by element to name the first bad row; the rows before it
 * are still delivered, and the failure is thrown at the step of the bad row (SDK_SPEC 3.10 rule 9), as when the scanner
 * refuses an element after good ones.
 *
 * @internal
 */
final class BatchJsonRows implements RowSource
{
    public const BATCH_ROWS = 1000;

    /** A batch holds at most this much element text, unless it is a single element (at most MAX_ROW_BYTES itself). */
    public const BATCH_BYTES = self::MAX_ROW_BYTES;

    /** An integer that may not fit the platform int (PHP_INT_MAX has 19 digits). */
    private const LONG_DIGIT_RUN = '/\d{19}/';

    /**
     * `json_decode()` accepts at most DEPTH - 1 levels, and the batch array is one of them: an element of up to 511
     * levels, exactly what json-machine accepts (depth 512 for the element alone).
     */
    private const DEPTH = 513;

    /**
     * `simdjson_decode()` accepts DEPTH levels, but counts the leaf scalar as one [observed 2026-11-01]; with 512 it
     * refuses an element of 511 levels around a scalar, which `json_decode()` then decodes, and an element of 512
     * levels of empty containers, which `json_decode()` refuses as well.
     */
    private const SIMDJSON_DEPTH = 512;

    private readonly JsonArrayScanner $scanner;

    /** @var list<mixed> */
    private array $rows = [];

    /** Offset of the next row of {@see $rows}. */
    private int $offset = 0;

    private mixed $row = null;

    private bool $ended = false;

    /** An element the scanner already handed out that did not fit the last batch: it opens the next one. */
    private ?string $pending = null;

    /** The failure of the batch behind {@see $rows}, thrown once its good rows have all been delivered. */
    private ?\Exception $failure = null;

    public function __construct(
        private readonly BodyReader $reader,
        private readonly string $path,
        private readonly bool $simdjson,
    ) {
        $this->scanner = new JsonArrayScanner($reader, $path);
    }

    public function next(): bool
    {
        $this->row = null;
        if (!\array_key_exists($this->offset, $this->rows)) {
            $this->rows = []; // the spent batch is freed before the next is decoded, so two never coexist
            $this->offset = 0;
            $this->throwPendingFailure();
            if (!$this->ended) {
                $this->rows = $this->nextBatch();
            }
        }
        $offset = $this->offset;
        if (!\array_key_exists($offset, $this->rows)) {
            return false;
        }
        $this->row = $this->rows[$offset];
        $this->offset = $offset + 1;

        return true;
    }

    /**
     * @throws DecodeException
     * @throws TransportException
     */
    private function throwPendingFailure(): void
    {
        $failure = $this->failure;
        if ($failure instanceof \Exception) {
            $this->failure = null;

            throw $failure;
        }
    }

    public function row(): mixed
    {
        return $this->row;
    }

    public function close(): void
    {
        $this->rows = []; // frees the decoded batch at once
        $this->reader->close();
    }

    /**
     * The rows of the next batch. A failure after the batch got its first element (the scanner refuses an element, the
     * body breaks off, or the batch holds a bad one) is kept in {@see $failure}: the rows before it are returned first.
     *
     * @return list<mixed>
     *
     * @throws DecodeException
     * @throws TransportException
     */
    private function nextBatch(): array
    {
        $first = $this->scanner->index() - ($this->pending === null ? 0 : 1);
        $elements = [];
        $bytes = 0;
        try {
            while (\count($elements) < self::BATCH_ROWS && $bytes < self::BATCH_BYTES) {
                $element = $this->pending ?? $this->scanner->next();
                $this->pending = null;
                if ($element === null) {
                    $this->ended = true;

                    break;
                }
                if ($elements !== [] && $bytes + \strlen($element) > self::BATCH_BYTES) {
                    $this->pending = $element;

                    break;
                }
                $elements[] = $element;
                $bytes += \strlen($element);
            }
        } catch (DecodeException|TransportException $e) {
            // A refused element or a body that breaks off: the elements before it are delivered first, then $e as is.
            if ($elements === []) {
                throw $e;
            }
            $this->ended = true;
            $this->failure = $e;
        }

        return $elements === [] ? [] : $this->decode($elements, $first);
    }

    /**
     * The elements are server bytes: they are sensitive parameters, so the trace of a {@see DecodeException} raised
     * below holds no element, and the `JsonException` (its trace holds the `json_decode()` arguments) is not chained.
     * A bad element does not take the good ones before it: they are returned, and the failure is kept for after them.
     *
     * @param non-empty-list<string> $elements
     *
     * @return list<mixed>
     *
     * @throws DecodeException
     */
    private function decode(#[\SensitiveParameter] array $elements, int $first): array
    {
        $rows = $this->tryDecode($elements);
        if ($rows !== null) {
            return $rows;
        }
        $bad = $this->firstBadElement($elements);
        if ($bad === 0) {
            throw RowPath::invalidJson($this->path, $first);
        }
        $prefix = $this->tryDecode(\array_slice($elements, 0, $bad));
        if ($prefix === null) {
            throw RowPath::invalidJson($this->path, $first);
        }
        $this->ended = true;
        $this->failure = RowPath::invalidJson($this->path, $first + $bad);

        return $prefix;
    }

    /**
     * @param list<string> $elements
     *
     * @return list<mixed>|null null when the text is not valid JSON
     */
    private function tryDecode(#[\SensitiveParameter] array $elements): ?array
    {
        $json = '[' . implode(',', $elements) . ']';
        if ($this->simdjson && preg_match(self::LONG_DIGIT_RUN, $json) === 0) {
            try {
                /** @var list<mixed> */
                return simdjson_decode($json, true, self::SIMDJSON_DEPTH);
            } catch (\SimdJsonException) {
                // Out-of-range integers included: json_decode() below decides, exactly.
            }
        }
        try {
            /** @var list<mixed> */
            return json_decode($json, true, self::DEPTH, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }
    }

    /**
     * The offset of the first element that is not valid JSON on its own (0 when each is: the batch is refused as a
     * whole, by its first row).
     *
     * @param non-empty-list<string> $elements
     */
    private function firstBadElement(#[\SensitiveParameter] array $elements): int
    {
        foreach ($elements as $offset => $element) {
            try {
                json_decode($element, true, self::DEPTH - 1, \JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $offset;
            }
        }

        return 0;
    }
}
