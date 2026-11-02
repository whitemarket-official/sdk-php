<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Deals;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Deals\Until;
use WhiteMarket\Sdk\Models\DealState;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

#[CoversClass(Until::class)]
final class UntilTest extends TestCase
{
    /**
     * Every status with the answer of each predicate, from the groups of WHITEMARKET_API.md 4: `offered` also accepts
     * exchanged and final, `exchangedOrCompleted`, `completed` and `final` accept exchanged and/or final.
     *
     * @return iterable<string, array{DealStatus|UnknownEnumValue, bool, bool, bool, bool}> status, offered, exchangedOrCompleted, completed, final
     */
    public static function statuses(): iterable
    {
        // In progress, before the offer.
        foreach ([DealStatus::Hold, DealStatus::Initiated, DealStatus::Queued, DealStatus::SellerSendingSteamOffer, DealStatus::WaitingSteamQueue, DealStatus::BuyerCancelRequested] as $status) {
            yield 'in progress ' . $status->value => [$status, false, false, false, false];
        }
        // In progress, offer made.
        foreach ([DealStatus::Offered, DealStatus::BuyerOffered, DealStatus::SellerOffered, DealStatus::NeedConfirmation] as $status) {
            yield 'offer made ' . $status->value => [$status, true, false, false, false];
        }
        yield 'exchanged EXCHANGED' => [DealStatus::Exchanged, true, true, false, false];
        yield 'successful COMPLETED' => [DealStatus::Completed, true, true, true, true];
        foreach ([DealStatus::Cancelled, DealStatus::BuyerCancelled, DealStatus::SellerCancelled, DealStatus::Expired, DealStatus::BuyerExpired, DealStatus::SellerExpired, DealStatus::Expiring] as $status) {
            yield 'failed ' . $status->value => [$status, true, true, true, true];
        }
        foreach ([DealStatus::SteamRollback, DealStatus::BuyerSteamRollback, DealStatus::SellerSteamRollback] as $status) {
            yield 'reversed ' . $status->value => [$status, true, true, true, true];
        }
        yield 'unknown status matches nothing' => [new UnknownEnumValue(DealStatus::class, 'SOMETHING_NEW'), false, false, false, false];
    }

    #[Test]
    public function theDataSetCoversEveryStatus(): void
    {
        $covered = [];
        foreach (self::statuses() as [$status]) {
            if ($status instanceof DealStatus) {
                $covered[] = $status;
            }
        }

        self::assertEqualsCanonicalizing(DealStatus::cases(), $covered);
    }

    #[Test]
    #[DataProvider('statuses')]
    public function eachPredicateAnswersForTheStatus(DealStatus|UnknownEnumValue $status, bool $offered, bool $exchangedOrCompleted, bool $completed, bool $final): void
    {
        $state = new DealState('d', null, $status, null, Money::usd('1'), null, null, null, null, null, null);

        self::assertSame(
            ['offered' => $offered, 'exchangedOrCompleted' => $exchangedOrCompleted, 'completed' => $completed, 'final' => $final],
            [
                'offered' => Until::offered()($state),
                'exchangedOrCompleted' => Until::exchangedOrCompleted()($state),
                'completed' => Until::completed()($state),
                'final' => Until::final()($state),
            ],
        );
    }
}
