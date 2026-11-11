<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Generated\Enum\WalletTransactionReason;
use WhiteMarket\Sdk\Models\TransactionReason;

#[CoversNothing]
final class TransactionReasonTest extends TestCase
{
    /**
     * A reason the schema adds must be added to the high-level enum too, or it reaches users as unknown.
     */
    #[Test]
    public function knowsEveryReasonOfTheSchema(): void
    {
        self::assertEqualsCanonicalizing(
            array_column(WalletTransactionReason::cases(), 'value'),
            array_column(TransactionReason::cases(), 'value'),
        );
    }
}
