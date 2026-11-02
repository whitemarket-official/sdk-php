<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Selling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Selling\ItemReference;

#[CoversClass(ItemReference::class)]
final class ItemReferenceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}> the bad value, the text shown in the message
     */
    public static function shownValues(): iterable
    {
        yield 'ANSI escape at the start' => ["\x1b[31mred", '\x1b[31mred'];
        yield 'newline at the start' => ["\nabc", '\x0aabc'];
        yield 'DEL' => ["a\x7fb", 'a\x7fb'];
        yield 'NUL' => ["a\0b", 'a\x00b'];
        yield 'a high byte is escaped, not passed through' => ["a\xffb", 'a\xffb'];
        yield 'exactly 40 characters are not cut' => [str_repeat('x', 40), str_repeat('x', 40)];
        yield '41 characters are cut with an ellipsis' => [str_repeat('x', 41), str_repeat('x', 40) . '...'];
        yield 'an escape that ends exactly at 40 stays whole' => [str_repeat('x', 36) . "\nzz", str_repeat('x', 36) . '\x0a' . '...'];
        yield 'an escape that would cross 40 is dropped whole' => [str_repeat('x', 37) . "\nzz", str_repeat('x', 37) . '...'];
    }

    #[Test]
    #[DataProvider('shownValues')]
    public function theMessageShowsAnEscapedAsciiValueCutOnAnEscapeBoundary(string $value, string $shown): void
    {
        try {
            ItemReference::assertProductId($value);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('The product id must be a UUID, got "' . $shown . '".', $e->getMessage());
        }
    }

    #[Test]
    public function aLongMultiByteValueGivesValidAsciiAndIsNotCutInsideAnEscape(): void
    {
        $value = str_repeat('Ж', 50);

        try {
            ItemReference::assertProductId($value);
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();
            self::assertSame(1, preg_match('//u', $message));
            self::assertSame(0, preg_match('/[\x80-\xff]/', $message), 'no byte >= 0x80');
            self::assertSame(1, preg_match('/^The product id must be a UUID, got "((?:\\\\x[0-9a-f]{2}){10})\.\.\."\.$/D', $message), 'ten whole escapes, then the ellipsis');
        }
    }
}
