<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Models\Money;

/**
 * Test amounts: a bad literal is a bug in the test, not a case under test.
 */
final class TestMoney
{
    private function __construct() {}

    public static function usd(string $amount): Money
    {
        try {
            return Money::usd($amount);
        } catch (\InvalidArgumentException $e) {
            throw new \LogicException('Bad test amount', 0, $e);
        }
    }
}
