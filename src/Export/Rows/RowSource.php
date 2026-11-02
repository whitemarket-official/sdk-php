<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export\Rows;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\TransportException;

/**
 * The raw rows of a streamed export body, pulled one at a time (ADR 0006): a JSON array element as decoded, or a CSV
 * record as a map from the header names. A pull API rather than a generator, so a failure is thrown by the call that
 * asked for the row.
 *
 * An implementation closes its body reader in {@see close()}, which must be safe after {@see next()} has thrown.
 * Closing at the end and on a failure, idempotency, and ending the rows after close() are added by
 * {@see GuardedRowSource}, which RowIterator wraps every source in. A failure of the body itself is the reader's {@see TransportException}, unchanged; a body that is not the expected shape is a
 * {@see DecodeException} whose path names the row (`products[3]`), with no server bytes in the message.
 *
 * @internal used by {@see RowIterator}
 */
interface RowSource
{
    /**
     * The longest row (a JSON array element or a CSV record) every source accepts, in bytes. A longer row is refused:
     * at once by the batch decoder and the CSV reader, by the streaming JSON parsers (json-machine, yajl) at the latest
     * {@see JsonArrayChunks::SLICE_BYTES} * 3 bytes past the limit.
     *
     * 128 KiB: a `foreach` over the rows keeps the previous row alive while the next one is decoded, so the worst case
     * is two decoded rows (owner decision).
     */
    public const MAX_ROW_BYTES = 131_072;

    /**
     * Moves to the next row: true when there is one ({@see row()}), false at the end of the body.
     *
     * @throws TransportException
     * @throws DecodeException
     */
    public function next(): bool;

    /**
     * The row the last successful {@see next()} moved to.
     */
    public function row(): mixed;

    /**
     * Stops reading and closes the body.
     *
     * @throws TransportException the client's stream failed to close
     */
    public function close(): void;
}
