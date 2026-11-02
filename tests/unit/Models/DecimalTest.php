<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\Decimal;

#[CoversClass(Decimal::class)]
final class DecimalTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validValues(): iterable
    {
        yield 'server money with trailing zeros' => ['114.300'];
        yield 'small fraction' => ['0.020'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-12.5'];
        yield 'leading zeros' => ['007.10'];
        yield 'beyond float precision' => ['12345678901234567890.123456789012345678901'];
    }

    #[Test]
    #[DataProvider('validValues')]
    public function keepsTheValueExactlyAsGiven(string $value): void
    {
        $decimal = Decimal::of($value);

        self::assertSame($value, $decimal->toString());
        self::assertSame($value, (string) $decimal);
        self::assertSame('"' . $value . '"', json_encode($decimal, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'empty' => [''];
        yield 'exponent' => ['1e-7'];
        yield 'plus sign' => ['+1'];
        yield 'leading dot' => ['.5'];
        yield 'trailing dot' => ['5.'];
        yield 'comma' => ['1,5'];
        yield 'spaces' => [' 1'];
        yield 'trailing newline' => ["1\n"];
        yield 'minus only' => ['-'];
        yield 'two dots' => ['1.2.3'];
        yield 'not a number' => ['NaN'];
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function rejectsAnythingButAPlainDecimal(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Decimal::of($value);
    }

    #[Test]
    #[DataProvider('invalidValues')]
    public function tryOfReturnsNullForAnythingButAPlainDecimal(string $value): void
    {
        self::assertNull(Decimal::tryOf($value));
    }

    #[Test]
    #[DataProvider('validValues')]
    public function tryOfKeepsAValidValueExactly(string $value): void
    {
        self::assertSame($value, Decimal::tryOf($value)?->toString());
    }

    #[Test]
    public function theRejectionMessageOfOfNamesTheValueButTryOfNeverThrows(): void
    {
        try {
            Decimal::of('abc');
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('abc', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function comparisons(): iterable
    {
        yield 'trailing zeros do not matter' => ['1.50', '1.5', 0];
        yield 'leading zeros do not matter' => ['007', '7', 0];
        yield 'negative zero equals zero' => ['-0.00', '0', 0];
        yield 'longer integer part is larger' => ['10', '9.99', 1];
        yield 'fraction decides' => ['1.05', '1.5', -1];
        yield 'fraction of different length' => ['0.1', '0.09', 1];
        yield 'one more digit far right' => ['0.0000000000000000001', '0', 1];
        yield 'beyond float precision' => ['9007199254740993', '9007199254740992', 1];
        yield 'negative is smaller than positive' => ['-1', '0.5', -1];
        yield 'zero is larger than negative' => ['0', '-0.01', 1];
        yield 'larger negative magnitude is smaller' => ['-2', '-1.99', -1];
        yield 'equal negatives' => ['-3.10', '-3.1', 0];
    }

    #[Test]
    #[DataProvider('comparisons')]
    public function comparesNumerically(string $left, string $right, int $expected): void
    {
        $a = Decimal::of($left);
        $b = Decimal::of($right);

        self::assertSame($expected, $a->compare($b));
        self::assertSame(-$expected, $b->compare($a));
        self::assertSame($expected === 0, $a->equals($b));
    }
}
