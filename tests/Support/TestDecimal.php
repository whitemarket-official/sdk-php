<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Models\Decimal;

/**
 * Test decimals: a bad literal is a bug in the test, not a case under test.
 */
final class TestDecimal
{
    private function __construct() {}

    public static function of(string $value): Decimal
    {
        try {
            return Decimal::of($value);
        } catch (\InvalidArgumentException $e) {
            throw new \LogicException('Bad test decimal', 0, $e);
        }
    }
}
