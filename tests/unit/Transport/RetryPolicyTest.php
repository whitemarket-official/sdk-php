<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\GraphQL\OperationType;
use WhiteMarket\Sdk\Tests\Support\FixedRandomSource;
use WhiteMarket\Sdk\Transport\RetryPolicy;

#[CoversClass(RetryPolicy::class)]
final class RetryPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{OperationType, int, ?int, ?int, bool}>
     */
    public static function decisions(): iterable
    {
        yield 'query, network error' => [OperationType::Query, 1, null, null, true];
        yield 'query, 429' => [OperationType::Query, 1, 429, null, true];
        yield 'query, 502' => [OperationType::Query, 1, 502, null, true];
        yield 'query, 503' => [OperationType::Query, 1, 503, null, true];
        yield 'query, 504' => [OperationType::Query, 1, 504, null, true];
        yield 'query, 500' => [OperationType::Query, 1, 500, null, true];
        yield 'query, 501 is not in the table' => [OperationType::Query, 1, 501, null, false];
        yield 'query, 507 is not in the table' => [OperationType::Query, 1, 507, null, false];
        yield 'query, 599 is not in the table' => [OperationType::Query, 1, 599, null, false];
        yield 'mutation, 500' => [OperationType::Mutation, 1, 500, null, false];
        yield 'query, 400' => [OperationType::Query, 1, 400, null, false];
        yield 'query, 401 is handled by auth, not by retry' => [OperationType::Query, 1, 401, null, false];
        yield 'query, 200 with errors' => [OperationType::Query, 1, 200, null, false];
        yield 'query, second attempt of three' => [OperationType::Query, 2, 503, null, true];
        yield 'query, last attempt' => [OperationType::Query, 3, 503, null, false];
        yield 'query, beyond the last attempt' => [OperationType::Query, 4, 503, null, false];
        yield 'query, 503 with Retry-After -> the caller decides' => [OperationType::Query, 1, 503, 7, false];
        yield 'query, 429 with Retry-After 0 -> the caller decides' => [OperationType::Query, 1, 429, 0, false];
        yield 'query, 502 with Retry-After -> header not part of 502, backoff' => [OperationType::Query, 1, 502, 7, true];
        yield 'query, 504 with Retry-After -> header not part of 504, backoff' => [OperationType::Query, 1, 504, 7, true];
        yield 'mutation, network error' => [OperationType::Mutation, 1, null, null, false];
        yield 'mutation, 503' => [OperationType::Mutation, 1, 503, null, false];
        yield 'mutation, 429' => [OperationType::Mutation, 1, 429, null, false];
    }

    #[Test]
    #[DataProvider('decisions')]
    public function retriesOnlyQueriesOnTransientFailures(OperationType $type, int $attempt, ?int $status, ?int $retryAfter, bool $expected): void
    {
        self::assertSame($expected, (new RetryPolicy())->shouldRetry($type, $attempt, $status, $retryAfter));
    }

    /**
     * @return iterable<string, array{int, ?int, ?int, bool}>
     */
    public static function readDecisions(): iterable
    {
        yield 'network error' => [1, null, null, true];
        yield '429' => [1, 429, null, true];
        yield '500' => [1, 500, null, true];
        yield '502' => [1, 502, null, true];
        yield '503' => [1, 503, null, true];
        yield '504' => [1, 504, null, true];
        yield '501 (every 5xx is retried)' => [1, 501, null, true];
        yield '507 (every 5xx is retried)' => [1, 507, null, true];
        yield '599 (upper bound of 5xx)' => [1, 599, null, true];
        yield '600 is not 5xx' => [1, 600, null, false];
        yield '499 is not 5xx' => [1, 499, null, false];
        yield '501 with Retry-After -> backoff (only 429 and 503 honour it)' => [1, 501, 7, true];
        yield '404' => [1, 404, null, false];
        yield '304' => [1, 304, null, false];
        yield 'second attempt of three' => [2, 503, null, true];
        yield 'last attempt' => [3, 503, null, false];
        yield '503 with Retry-After -> the caller decides' => [1, 503, 7, false];
        yield '429 with Retry-After -> the caller decides' => [1, 429, 0, false];
        yield '502 with Retry-After -> backoff' => [1, 502, 7, true];
    }

    #[Test]
    #[DataProvider('readDecisions')]
    public function retriesAnExportGetOnNetworkErrorsEvery5xxAnd429(int $attempt, ?int $status, ?int $retryAfter, bool $expected): void
    {
        self::assertSame($expected, (new RetryPolicy())->shouldRetryRead($attempt, $status, $retryAfter));
    }

    #[Test]
    public function neverRetriesAMutationEvenWithManyAttempts(): void
    {
        $policy = new RetryPolicy(maxAttempts: 100);

        self::assertFalse($policy->shouldRetry(OperationType::Mutation, 1, 503));
        self::assertTrue($policy->shouldRetry(OperationType::Query, 99, 503));
        self::assertFalse($policy->shouldRetry(OperationType::Query, 100, 503));
    }

    #[Test]
    public function noneMakesOneAttempt(): void
    {
        self::assertFalse(RetryPolicy::none()->shouldRetry(OperationType::Query, 1, 503));
    }

    /**
     * @return iterable<string, array{int, float, float}>
     */
    public static function delays(): iterable
    {
        yield 'attempt 0 counts as the first' => [0, 1.0, 0.5];
        yield 'after attempt 1, full jitter top' => [1, 1.0, 0.5];
        yield 'after attempt 2 doubles' => [2, 1.0, 1.0];
        yield 'after attempt 3 doubles again' => [3, 1.0, 2.0];
        yield 'half jitter' => [3, 0.5, 1.0];
        yield 'zero jitter' => [3, 0.0, 0.0];
        yield 'after attempt 7 hits the cap' => [7, 1.0, 30.0];
        yield 'after attempt 5000 stays at the cap' => [5000, 1.0, 30.0];
    }

    #[Test]
    #[DataProvider('delays')]
    public function backsOffExponentiallyWithFullJitterAndACap(int $attempt, float $jitter, float $expected): void
    {
        self::assertSame($expected, (new RetryPolicy())->delay($attempt, new FixedRandomSource($jitter)));
    }

    #[Test]
    public function zeroBaseDelayNeverWaits(): void
    {
        self::assertSame(0.0, (new RetryPolicy(baseDelaySeconds: 0.0))->delay(5000, new FixedRandomSource(1.0)));
    }

    /**
     * @return iterable<string, array{\Closure(): RetryPolicy}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'zero attempts' => [static fn(): RetryPolicy => new RetryPolicy(maxAttempts: 0)];
        yield 'negative base delay' => [static fn(): RetryPolicy => new RetryPolicy(baseDelaySeconds: -0.1)];
        yield 'factor below one' => [static fn(): RetryPolicy => new RetryPolicy(factor: 0.9)];
        yield 'negative cap' => [static fn(): RetryPolicy => new RetryPolicy(maxDelaySeconds: -1.0)];
    }

    /**
     * @param \Closure(): RetryPolicy $create
     */
    #[Test]
    #[DataProvider('invalidOptions')]
    public function rejectsInvalidOptions(\Closure $create): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $create();
    }

    #[Test]
    public function acceptsTheSmallestValidOptions(): void
    {
        $policy = new RetryPolicy(maxAttempts: 1, baseDelaySeconds: 0.0, factor: 1.0, maxDelaySeconds: 0.0);

        self::assertSame(1, $policy->maxAttempts);
    }
}
