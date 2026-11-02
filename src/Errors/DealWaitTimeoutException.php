<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

use WhiteMarket\Sdk\Models\DealState;

/**
 * `deals->waitFor()` gave up: the condition did not hold within the timeout. Not an API failure, so it is not a
 * {@see GraphQLException} or an HTTP error; it is the caller's own deadline (not in SDK_SPEC 1.5, see the spec proposals).
 * The deal itself is unchanged by the wait: look at {@see $lastState} and decide.
 */
final class DealWaitTimeoutException extends WhiteMarketException
{
    /**
     * @param string $idOrCustomId the reference that was waited for
     * @param ?DealState $lastState the last state the server returned; null when the deal was never visible
     * @param float $timeout the timeout in seconds that elapsed
     */
    public function __construct(
        public readonly string $idOrCustomId,
        public readonly ?DealState $lastState,
        public readonly float $timeout,
    ) {
        parent::__construct(\sprintf(
            'Deal "%s" did not reach the awaited state within %s seconds%s.',
            $idOrCustomId,
            rtrim(rtrim(\sprintf('%.3f', $timeout), '0'), '.'),
            $lastState instanceof DealState ? ' (last status ' . $lastState->status->value . ')' : ' (the deal was never visible)',
        ));
    }
}
