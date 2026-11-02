<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\LiveConnectionException;
use WhiteMarket\Sdk\Errors\ServerText;

#[CoversClass(ServerText::class)]
#[CoversClass(LiveConnectionException::class)]
final class ServerTextTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'plain' => ['normal closure', 'normal closure'];
        yield 'empty' => ['', ''];
        yield 'control and high bytes' => ["a\x00b\r\n\x7f\xc3\xa9", 'a?b?????'];
        yield '128 bytes kept' => [str_repeat('a', ServerText::MAX_BYTES), str_repeat('a', 128)];
        yield '129 bytes cut' => [str_repeat('a', 129), str_repeat('a', 128)];
        yield 'multibyte at the cut' => [str_repeat('a', 127) . "\xc3\xa9", str_repeat('a', 127) . '?'];
    }

    #[Test]
    #[DataProvider('texts')]
    public function theTextHasPrintableAsciiOnlyAndIsCut(string $text, string $expected): void
    {
        self::assertSame($expected, ServerText::printable($text));
    }

    #[Test]
    public function liveConnectionExceptionKeepsTheReasonPrintable(): void
    {
        $e = new LiveConnectionException(3500, "bad\x00" . str_repeat('x', 200));

        self::assertSame(128, \strlen($e->reason));
        self::assertStringStartsWith('bad?', $e->reason);
        self::assertSame('The live connection failed with code 3500.', $e->getMessage());
    }
}
