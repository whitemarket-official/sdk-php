<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * The attempts one GraphQL call has used, shared by its first send and the replay after a 401 (SDK_SPEC 1.4): the
 * replay continues the count, so a query sends at most `maxAttempts` requests in total, and a 401 on the last attempt
 * is still replayed once (that replay is not retried). One instance per call, never shared between calls.
 *
 * @internal used by {@see HttpTransport} and {@see \WhiteMarket\Sdk\GraphQL\GraphQLExecutor}
 */
final class AttemptBudget
{
    private int $used = 0;

    /** The number the next attempt carries. */
    public function nextAttempt(): int
    {
        return $this->used + 1;
    }

    public function record(int $attempt): void
    {
        $this->used = $attempt;
    }
}
