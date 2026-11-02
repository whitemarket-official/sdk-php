<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\GeneralServerException;
use WhiteMarket\Sdk\Errors\MutationOutcomeUnknownException;

#[CoversClass(MutationOutcomeUnknownException::class)]
final class MutationOutcomeUnknownExceptionTest extends TestCase
{
    #[Test]
    public function namesTheOperationAndWhereToCheckAndKeepsTheCause(): void
    {
        $cause = new GeneralServerException(503, '');

        $e = new MutationOutcomeUnknownException('wallet.transfer', $cause, 'wallet.transfers()');

        self::assertSame('wallet.transfer', $e->operation);
        self::assertSame($cause, $e->getPrevious());
        self::assertSame('wallet.transfer may or may not have been executed; check wallet.transfers() before trying again.', $e->getMessage());
    }

    #[Test]
    public function theMessageDoesNotCarryTheCausesMessage(): void
    {
        $e = new MutationOutcomeUnknownException('wallet.takeCredit', new \RuntimeException('amount 123.45 to 76561190000000001'), 'wallet.credit()');

        self::assertStringNotContainsString('123.45', $e->getMessage());
        self::assertStringNotContainsString('76561190000000001', $e->getMessage());
    }
}
