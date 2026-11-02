<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Tests\Support\FrozenClock;
use WhiteMarket\Sdk\Transport\RetryAfter;

#[CoversClass(RetryAfter::class)]
final class RetryAfterTest extends TestCase
{
    /**
     * @return iterable<string, array{string, ?int}>
     */
    public static function headers(): iterable
    {
        yield 'zero seconds' => ['0', 0];
        yield 'seconds' => ['7', 7];
        yield 'seconds with spaces' => [' 120 ', 120];
        yield 'nine digits' => ['123456789', 123_456_789];
        yield 'ten digits saturate' => ['99999999999999999999', 999_999_999];
        yield 'HTTP-date in 90 s' => ['Thu, 01 Jan 2026 00:01:30 GMT', 90];
        yield 'HTTP-date now' => ['Thu, 01 Jan 2026 00:00:00 GMT', 0];
        yield 'HTTP-date in the past' => ['Wed, 31 Dec 2025 23:59:00 GMT', 0];
        yield 'RFC 850 date in 90 s' => ['Thursday, 01-Jan-26 00:01:30 GMT', 90];
        yield 'asctime date in 90 s, one-digit day' => ['Thu Jan  1 00:01:30 2026', 90];
        yield 'asctime date, two-digit day' => ['Sat Jan 10 00:00:00 2026', 777_600];
        yield 'empty' => ['', null];
        yield 'negative' => ['-5', null];
        yield 'fraction' => ['1.5', null];
        yield 'garbage' => ['soon', null];
        yield 'date without GMT' => ['Thu, 01 Jan 2026 00:01:30', null];
        yield 'date with wrong weekday' => ['Fri, 01 Jan 2026 00:01:30 GMT', null];
        yield 'ISO date' => ['2026-01-01T00:01:30Z', null];
        yield 'RFC 850 date with wrong weekday' => ['Friday, 01-Jan-26 00:01:30 GMT', null];
        yield 'asctime date with zero-padded day' => ['Thu Jan 01 00:01:30 2026', null];
        yield 'asctime date with wrong weekday' => ['Fri Jan  1 00:01:30 2026', null];
        // Accepted limitations (docs/DEVIATIONS.md): valid per RFC 9110, parsed as no header.
        yield 'leap second' => ['Sat, 31 Dec 2016 23:59:60 GMT', null];
        yield 'RFC 850 year 71 outside the PHP window' => ['Thursday, 01-Jan-71 00:00:00 GMT', null];
        yield 'repeated header' => ['120, 120', null];
    }

    #[Test]
    #[DataProvider('headers')]
    public function parsesSecondsAndHttpDates(string $header, ?int $expected): void
    {
        self::assertSame($expected, RetryAfter::seconds($header, (new FrozenClock())->now()));
    }
}
