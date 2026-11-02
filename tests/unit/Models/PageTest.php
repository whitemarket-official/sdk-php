<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\Page;

#[CoversClass(Page::class)]
final class PageTest extends TestCase
{
    #[Test]
    public function iteratesAndCountsItsItemsInOrder(): void
    {
        $page = new Page(['b', 'a', 'c'], 'cursor-3', true, 42);

        self::assertSame(['b', 'a', 'c'], iterator_to_array($page, false));
        self::assertCount(3, $page);
        self::assertSame('cursor-3', $page->endCursor);
        self::assertTrue($page->hasNextPage);
        self::assertSame(42, $page->totalCount);
    }

    #[Test]
    public function anEmptyLastPageHasNothingToIterate(): void
    {
        $page = new Page([], null, false, null);

        self::assertSame([], iterator_to_array($page, false));
        self::assertCount(0, $page);
    }
}
