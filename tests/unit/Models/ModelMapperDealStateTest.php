<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Generated\Operation\Query\DealBuyerStatuses\Result;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DealCancelReason;
use WhiteMarket\Sdk\Models\DealState;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\ModelMapper;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

#[CoversClass(ModelMapper::class)]
#[CoversClass(DealState::class)]
final class ModelMapperDealStateTest extends TestCase
{
    private const PATH = 'deal_buyer_statuses[0]';

    #[Test]
    public function mapsADealStateWithEveryField(): void
    {
        $state = (new ModelMapper())->dealState($this->state([
            'cancelReason' => null,
            'isTradeProtected' => true,
            'settlementTimeAt' => '2026-11-07T10:00:00+00:00',
            'reversePenaltyValue' => ['currency' => 'USD', 'value' => '0.500'],
            'reverseRefundValue' => ['currency' => 'USD', 'value' => '9.900'],
        ]), self::PATH);

        self::assertSame('7b0e6a52-3f0c-4d8e-9a51-0c2f5e1d9a10', $state->id);
        self::assertSame('synthetic-custom-id-1', $state->customId);
        self::assertSame(DealStatus::Exchanged, $state->status);
        self::assertNull($state->cancelReason);
        self::assertSame('114.300 USD', (string) $state->price);
        self::assertSame(Currency::Usd, $state->price->currency);
        self::assertSame('76561190000000001', $state->steamId);
        self::assertSame('9000000000000000001', $state->tradeId);
        self::assertTrue($state->isTradeProtected);
        self::assertSame('2026-11-07T10:00:00+00:00', $state->settlementTimeAt?->format(\DATE_ATOM));
        self::assertSame('0.500 USD', (string) $state->reversePenalty);
        self::assertSame('9.900 USD', (string) $state->reverseRefund);
        self::assertTrue($state->awaitingTradeProtection());
    }

    #[Test]
    public function leavesAbsentOptionalValuesNull(): void
    {
        $state = (new ModelMapper())->dealState($this->state([]), self::PATH);

        self::assertNull($state->reversePenalty);
        self::assertNull($state->reverseRefund);
        self::assertNull($state->isTradeProtected);
        self::assertNull($state->settlementTimeAt);
        self::assertFalse($state->awaitingTradeProtection());
    }

    #[Test]
    public function mapsAStatusTheSchemaSnapshotLacksButTheModelKnows(): void
    {
        $state = (new ModelMapper())->dealState($this->state(['status' => 'QUEUED']), self::PATH);

        self::assertSame(DealStatus::Queued, $state->status);
    }

    #[Test]
    public function keepsAnUnknownStatusAsItsRawValue(): void
    {
        $state = (new ModelMapper())->dealState($this->state(['status' => 'SOMETHING_NEW']), self::PATH);

        self::assertInstanceOf(UnknownEnumValue::class, $state->status);
        self::assertSame(DealStatus::class, $state->status->enum);
        self::assertSame('SOMETHING_NEW', $state->status->value);
        self::assertFalse($state->awaitingTradeProtection());
    }

    #[Test]
    public function mapsKnownAndUnknownCancelReasons(): void
    {
        $known = (new ModelMapper())->dealState($this->state(['cancelReason' => 'NO_FUNDS']), self::PATH);
        $unknown = (new ModelMapper())->dealState($this->state(['cancelReason' => 'NEW_REASON']), self::PATH);

        self::assertSame(DealCancelReason::NoFunds, $known->cancelReason);
        self::assertInstanceOf(UnknownEnumValue::class, $unknown->cancelReason);
        self::assertSame(DealCancelReason::class, $unknown->cancelReason->enum);
        self::assertSame('NEW_REASON', $unknown->cancelReason->value);
    }

    #[Test]
    public function keepsAnUnknownCurrencyAsItsRawValue(): void
    {
        $state = (new ModelMapper())->dealState($this->state(['price' => ['currency' => 'EUR', 'value' => '1.00']]), self::PATH);

        self::assertInstanceOf(UnknownEnumValue::class, $state->price->currency);
        self::assertSame(Currency::class, $state->price->currency->enum);
        self::assertSame('1.00 EUR', (string) $state->price);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function malformedMoney(): iterable
    {
        yield 'price as a float-like exponent' => [['price' => ['currency' => 'USD', 'value' => '1e3']], self::PATH . '.price.value'];
        yield 'price empty' => [['price' => ['currency' => 'USD', 'value' => '']], self::PATH . '.price.value'];
        yield 'reverse penalty' => [['reversePenaltyValue' => ['currency' => 'USD', 'value' => 'abc']], self::PATH . '.reversePenaltyValue.value'];
        yield 'reverse refund' => [['reverseRefundValue' => ['currency' => 'USD', 'value' => '1,5']], self::PATH . '.reverseRefundValue.value'];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[Test]
    #[DataProvider('malformedMoney')]
    public function rejectsAMoneyValueThatIsNotADecimalWithItsPath(array $override, string $path): void
    {
        try {
            (new ModelMapper())->dealState($this->state($override), self::PATH);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame($path, $e->path);
            self::assertNull($e->getPrevious(), 'the raw value must not travel in the chain');
        }
    }

    /**
     * A synthetic `MarketDealState` as the server sends it, decoded by the generated class.
     *
     * @param array<string, mixed> $override
     */
    private function state(array $override): Result
    {
        return Result::fromValue($override + [
            'cancelReason' => null,
            'customId' => 'synthetic-custom-id-1',
            'id' => '7b0e6a52-3f0c-4d8e-9a51-0c2f5e1d9a10',
            'isTradeProtected' => null,
            'settlementTimeAt' => null,
            'status' => 'EXCHANGED',
            'steamId' => '76561190000000001',
            'tradeId' => '9000000000000000001',
            'price' => ['currency' => 'USD', 'value' => '114.300'],
            'reversePenaltyValue' => null,
            'reverseRefundValue' => null,
        ], self::PATH);
    }
}
