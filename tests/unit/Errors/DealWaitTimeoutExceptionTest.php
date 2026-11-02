<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DealWaitTimeoutException;
use WhiteMarket\Sdk\Models\DealState;
use WhiteMarket\Sdk\Models\DealStatus;
use WhiteMarket\Sdk\Models\Money;

#[CoversClass(DealWaitTimeoutException::class)]
final class DealWaitTimeoutExceptionTest extends TestCase
{
    private function state(): DealState
    {
        return new DealState('deal-1', 'order-1', DealStatus::Offered, null, Money::usd('1'), null, null, null, null, null, null);
    }

    #[Test]
    public function carriesTheReferenceTheLastStateAndTheTimeout(): void
    {
        $state = $this->state();

        $e = new DealWaitTimeoutException('order-1', $state, 12.5);

        self::assertSame(['order-1', $state, 12.5], [$e->idOrCustomId, $e->lastState, $e->timeout]);
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function timeouts(): iterable
    {
        yield 'whole seconds' => [30.0, '30 seconds'];
        yield 'zero' => [0.0, '0 seconds'];
        yield 'fraction' => [0.5, '0.5 seconds'];
        yield 'trailing zeros are dropped' => [10.25, '10.25 seconds'];
    }

    #[Test]
    #[DataProvider('timeouts')]
    public function namesTheTimeoutAndTheLastStatusInTheMessage(float $timeout, string $expected): void
    {
        $message = (new DealWaitTimeoutException('order-1', $this->state(), $timeout))->getMessage();

        self::assertSame(\sprintf('Deal "order-1" did not reach the awaited state within %s (last status OFFERED).', $expected), $message);
    }

    #[Test]
    public function saysWhenTheDealWasNeverVisible(): void
    {
        $message = (new DealWaitTimeoutException('order-1', null, 5.0))->getMessage();

        self::assertSame('Deal "order-1" did not reach the awaited state within 5 seconds (the deal was never visible).', $message);
    }
}
