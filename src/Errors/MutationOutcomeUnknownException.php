<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * A non-idempotent mutation failed in a way that does not tell whether the server ran it (a timeout, a 5xx, an
 * unreadable response). It was sent once and is never resent, so the effect may or may not exist: do not send it again
 * until you have checked. {@see getPrevious()} is the original failure. A failure that proves the server did not run the
 * mutation (a bad request, a rejected credential, a rate limit, a GraphQL error of the root field) is not wrapped:
 * it propagates as its own exception.
 *
 * The message names the operation and where to check, never an amount or an id.
 */
final class MutationOutcomeUnknownException extends WhiteMarketException
{
    /**
     * @param string $operation the SDK method, for example `wallet.transfer`
     * @param \Throwable $cause the original failure, kept as {@see getPrevious()}
     * @param string $checkHint what to read to learn the outcome, for example `wallet.transfers()`
     */
    public function __construct(
        public readonly string $operation,
        \Throwable $cause,
        string $checkHint,
    ) {
        parent::__construct(
            \sprintf('%s may or may not have been executed; check %s before trying again.', $operation, $checkHint),
            0,
            $cause,
        );
    }
}
