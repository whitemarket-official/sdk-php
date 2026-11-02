<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use WhiteMarket\Sdk\GraphQL\OperationType;

/**
 * When and how long to wait before repeating a GraphQL request or an export GET (SDK_SPEC 1.4). All durations are in
 * seconds.
 *
 * Mutations are never retried, whatever the configuration (CLAUDE.md rule 2). Queries are retried on a network error
 * before a response and on 429, 500, 502, 503, 504, at most `maxAttempts` attempts in total. The delay is exponential with
 * full jitter (`random(0, min(maxDelaySeconds, baseDelaySeconds * factor^(attempt - 1)))`). A 429 or 503 with
 * `Retry-After` is not retried: the caller gets the exception with `retryAfter` and decides when to try again, so the
 * SDK never blocks for a server-chosen time (owner decision 2026-10-31). On 502 and 504 the header is ignored. An export
 * GET (read-only) is also retried on every other 5xx ({@see shouldRetryRead()}).
 */
final readonly class RetryPolicy
{
    private const RETRYABLE_STATUSES = [429, 500, 502, 503, 504];

    /** The statuses whose exception carries `retryAfter` (SDK_SPEC 1.5). */
    private const RETRY_AFTER_STATUSES = [429, 503];

    public function __construct(
        public int $maxAttempts = 3,
        public float $baseDelaySeconds = 0.5,
        public float $factor = 2.0,
        public float $maxDelaySeconds = 30.0,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }
        if ($baseDelaySeconds < 0 || $factor < 1 || $maxDelaySeconds < 0) {
            throw new \InvalidArgumentException('Delays must not be negative and factor must be at least 1.');
        }
    }

    public static function none(): self
    {
        return new self(maxAttempts: 1);
    }

    /**
     * Whether attempt number `attempt` (1-based) that failed with `status` (`null`: no response) may be repeated.
     *
     * @internal called by the transport
     */
    public function shouldRetry(OperationType $type, int $attempt, ?int $status, ?int $retryAfter = null): bool
    {
        return $type === OperationType::Query && $this->mayRepeat($attempt, $status, $retryAfter, $status !== null && \in_array($status, self::RETRYABLE_STATUSES, true));
    }

    /**
     * {@see shouldRetry()} for a GET of an export file (SDK_SPEC 1.4 "Export GET": network error, 5xx, 429): every
     * status 500-599 and 429, not only the table of a query; the attempts and the `Retry-After` rule are the same.
     *
     * @internal called by the export transport
     */
    public function shouldRetryRead(int $attempt, ?int $status, ?int $retryAfter = null): bool
    {
        return $this->mayRepeat($attempt, $status, $retryAfter, $status !== null && ($status === 429 || ($status >= 500 && $status <= 599)));
    }

    /**
     * @param bool $statusRetryable the status (`null`: a network error, always retryable) is one the caller repeats
     */
    private function mayRepeat(int $attempt, ?int $status, ?int $retryAfter, bool $statusRetryable): bool
    {
        if ($attempt >= $this->maxAttempts) {
            return false;
        }
        if ($retryAfter !== null && \in_array($status, self::RETRY_AFTER_STATUSES, true)) {
            return false;
        }

        return $status === null || $statusRetryable;
    }

    /**
     * Seconds to wait after attempt number `attempt` (1-based) failed.
     *
     * @internal called by the transport
     */
    public function delay(int $attempt, RandomSource $random): float
    {
        if ($this->baseDelaySeconds === 0.0) {
            return 0.0;
        }
        // factor ** n overflows to INF for large n; min() then returns the cap.
        $ceiling = min($this->maxDelaySeconds, $this->baseDelaySeconds * $this->factor ** max(0, $attempt - 1));

        return $random->fraction() * $ceiling;
    }
}
