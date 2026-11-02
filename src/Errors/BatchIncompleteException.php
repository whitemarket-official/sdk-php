<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

use WhiteMarket\Sdk\Selling\BatchItemResult;
use WhiteMarket\Sdk\Selling\BatchOutcome;

/**
 * A `*Many` call of `$client->selling` could not give every item a server-reported outcome (P12): a request failed, or
 * the server did not report some items. `$results` has one entry per item the caller passed, in the caller's order:
 * items of earlier requests keep their outcome, the items of the failed request are `Unknown` (it may have run) or
 * `NotSent` (the server rejected it before running it: HTTP 400/401/429, or a GraphQL request error without a path; an error at the
 * root field of the mutation does not count, the resolver may have acted on some items), later items are `NotSent`. {@see getPrevious()} is the failure
 * of the request, or null when the server only left items out of its answer. Never resend the whole list: send only the
 * `NotSent` items, and check the `Unknown` ones with `myProducts()`.
 */
final class BatchIncompleteException extends WhiteMarketException
{
    /**
     * @param list<BatchItemResult> $results
     */
    public function __construct(
        public readonly array $results,
        ?\Throwable $previous = null,
    ) {
        $counts = array_fill_keys(array_column(BatchOutcome::cases(), 'value'), 0);
        foreach ($results as $result) {
            ++$counts[$result->outcome->value];
        }
        parent::__construct(
            \sprintf(
                'Batch incomplete: %d succeeded, %d failed, %d unknown, %d not sent.',
                $counts[BatchOutcome::Succeeded->value],
                $counts[BatchOutcome::Failed->value],
                $counts[BatchOutcome::Unknown->value],
                $counts[BatchOutcome::NotSent->value],
            ),
            0,
            $previous,
        );
    }
}
