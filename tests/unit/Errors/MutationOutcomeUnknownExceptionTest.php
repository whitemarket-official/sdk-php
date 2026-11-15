<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

        $e = new MutationOutcomeUnknownException('wallet.transfer', $cause);

        self::assertSame('wallet.transfer', $e->operation);
        self::assertSame($cause, $e->getPrevious());
        self::assertSame('wallet.transfer may or may not have been executed; check wallet.transfers() before trying again.', $e->getMessage());
    }

    #[Test]
    public function theMessageDoesNotCarryTheCausesMessage(): void
    {
        $e = new MutationOutcomeUnknownException('wallet.takeCredit', new \RuntimeException('amount 123.45 to 76561190000000001'));

        self::assertStringNotContainsString('123.45', $e->getMessage());
        self::assertStringNotContainsString('76561190000000001', $e->getMessage());
    }

    /** @return iterable<string, array{string, string}> */
    public static function operations(): iterable
    {
        yield 'wallet.transfer -> wallet.transfers()' => ['wallet.transfer', 'wallet.transfers()'];
        yield 'wallet.takeCredit -> wallet.credit()' => ['wallet.takeCredit', 'wallet.credit()'];
        yield 'unknown operation -> names no method' => ['other.op', 'the result'];
    }

    #[Test]
    #[DataProvider('operations')]
    public function derivesWhereToCheckFromTheOperation(string $operation, string $where): void
    {
        $e = new MutationOutcomeUnknownException($operation, new \RuntimeException('x'));

        self::assertSame(\sprintf('%s may or may not have been executed; check %s before trying again.', $operation, $where), $e->getMessage());
    }
}
