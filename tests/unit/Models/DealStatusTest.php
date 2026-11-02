<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Generated\Enum\MarketDealCancelReason;
use WhiteMarket\Sdk\Generated\Enum\MarketDealStatus;
use WhiteMarket\Sdk\Models\DealCancelReason;
use WhiteMarket\Sdk\Models\DealStatus;

#[CoversClass(DealStatus::class)]
final class DealStatusTest extends TestCase
{
    /**
     * The classification table of WHITEMARKET_API.md section 4, row by row.
     *
     * @return iterable<string, array{DealStatus, string}>
     */
    public static function statusGroups(): iterable
    {
        $table = [
            'in progress' => ['HOLD', 'INITIATED', 'QUEUED', 'SELLER_SENDING_STEAM_OFFER', 'WAITING_STEAM_QUEUE',
                'BUYER_CANCEL_REQUESTED', 'OFFERED', 'BUYER_OFFERED', 'SELLER_OFFERED', 'NEED_CONFIRMATION'],
            'exchanged' => ['EXCHANGED'],
            'successful' => ['COMPLETED'],
            'failed' => ['CANCELLED', 'BUYER_CANCELLED', 'SELLER_CANCELLED', 'EXPIRED', 'BUYER_EXPIRED',
                'SELLER_EXPIRED', 'EXPIRING'],
            'reversed' => ['STEAM_ROLLBACK', 'BUYER_STEAM_ROLLBACK', 'SELLER_STEAM_ROLLBACK'],
        ];
        foreach ($table as $group => $values) {
            foreach ($values as $value) {
                yield $value . ' -> ' . $group => [DealStatus::from($value), $group];
            }
        }
    }

    #[Test]
    #[DataProvider('statusGroups')]
    public function belongsToExactlyOneGroup(DealStatus $status, string $group): void
    {
        $actual = [
            'in progress' => $status->isInProgress(),
            'exchanged' => $status->isExchanged(),
            'successful' => $status->isSuccessful(),
            'failed' => $status->isFailed(),
            'reversed' => $status->isReversed(),
        ];

        self::assertSame([$group], array_keys(array_filter($actual)));
        self::assertSame(\in_array($group, ['successful', 'failed', 'reversed'], true), $status->isFinal());
    }

    #[Test]
    public function theTableCoversEveryCase(): void
    {
        $classified = array_map(static fn(array $row): string => $row[0]->value, iterator_to_array(self::statusGroups(), false));

        self::assertEqualsCanonicalizing(array_column(DealStatus::cases(), 'value'), $classified);
    }

    /**
     * A value added to the schema must be added here too (and classified), or it reaches users as unknown.
     */
    #[Test]
    public function knowsEveryStatusOfTheSchema(): void
    {
        $schema = array_column(MarketDealStatus::cases(), 'value');

        self::assertSame([], array_diff($schema, array_column(DealStatus::cases(), 'value')));
    }

    #[Test]
    public function knowsEveryCancelReasonOfTheSchema(): void
    {
        self::assertEqualsCanonicalizing(
            array_column(MarketDealCancelReason::cases(), 'value'),
            array_column(DealCancelReason::cases(), 'value'),
        );
    }
}
