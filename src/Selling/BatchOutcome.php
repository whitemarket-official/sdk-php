<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Selling;

/**
 * What is known about one item of a batch (P12).
 */
enum BatchOutcome: string
{
    /** The server reported the item done. */
    case Succeeded = 'succeeded';

    /** The server reported the item failed: see {@see BatchItemResult::$errorCategory}. */
    case Failed = 'failed';

    /** The request that carried the item failed ambiguously or the server did not report the item: it may have been done. */
    case Unknown = 'unknown';

    /** The item was not sent, or the server rejected the request before running it. */
    case NotSent = 'not_sent';
}
