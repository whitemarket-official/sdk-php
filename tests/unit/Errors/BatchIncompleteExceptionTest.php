<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\BatchIncompleteException;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Selling\BatchItemResult;
use WhiteMarket\Sdk\Selling\BatchOutcome;

#[CoversClass(BatchIncompleteException::class)]
final class BatchIncompleteExceptionTest extends TestCase
{
    #[Test]
    public function carriesTheResultsAndTheFailureAndCountsEveryOutcomeInTheMessage(): void
    {
        $results = [
            new BatchItemResult(0, BatchOutcome::Succeeded),
            new BatchItemResult(1, BatchOutcome::Succeeded),
            new BatchItemResult(2, BatchOutcome::Failed, null, 'market.product.not_found', 'secret-looking item text'),
            new BatchItemResult(3, BatchOutcome::Unknown),
            new BatchItemResult(4, BatchOutcome::Unknown),
            new BatchItemResult(5, BatchOutcome::Unknown),
            new BatchItemResult(6, BatchOutcome::NotSent),
        ];
        $cause = new GeneralServerException(503, '');

        $e = new BatchIncompleteException($results, $cause);

        self::assertSame($results, $e->results);
        self::assertSame($cause, $e->getPrevious());
        self::assertSame('Batch incomplete: 2 succeeded, 1 failed, 3 unknown, 1 not sent.', $e->getMessage());
    }

    #[Test]
    public function theFailureIsOptionalForAnAnswerThatLeftItemsOut(): void
    {
        $e = new BatchIncompleteException([new BatchItemResult(0, BatchOutcome::Unknown)]);

        self::assertNull($e->getPrevious());
        self::assertSame('Batch incomplete: 0 succeeded, 0 failed, 1 unknown, 0 not sent.', $e->getMessage());
    }
}
