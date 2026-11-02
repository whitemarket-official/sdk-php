<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\GraphQLErrorEntry;
use WhiteMarket\Sdk\Errors\NotFoundException;
use WhiteMarket\Sdk\Errors\PurchaseOutcomeUnknownException;

#[CoversClass(PurchaseOutcomeUnknownException::class)]
final class PurchaseOutcomeUnknownExceptionTest extends TestCase
{
    #[Test]
    public function carriesTheCustomIdTheCauseAndTheLastLookupError(): void
    {
        $cause = new GeneralServerException(502, '');
        $lookup = new NotFoundException([new GraphQLErrorEntry('Deal not found', 'market.deal.not_found')]);

        $e = new PurchaseOutcomeUnknownException('order-7', $cause, $lookup);

        self::assertSame('order-7', $e->customId);
        self::assertSame($cause, $e->getPrevious());
        self::assertSame($lookup, $e->lastLookupError);
        self::assertSame(
            'Purchase outcome unknown for customId "order-7": market_buy failed ambiguously and the deal was not found by reconciliation. Look it up later with this customId.',
            $e->getMessage(),
        );
    }

    #[Test]
    public function theLastLookupErrorIsOptional(): void
    {
        self::assertNull((new PurchaseOutcomeUnknownException('order-7', new GeneralServerException(500, '')))->lastLookupError);
    }
}
