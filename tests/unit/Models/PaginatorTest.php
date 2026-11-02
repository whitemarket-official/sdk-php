<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Models;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Models\Page;
use WhiteMarket\Sdk\Models\Paginator;

#[CoversClass(Paginator::class)]
final class PaginatorTest extends TestCase
{
    /**
     * @param list<string> $items
     *
     * @return Page<string>
     */
    private function page(array $items, ?string $endCursor, bool $hasNextPage): Page
    {
        return new Page($items, $endCursor, $hasNextPage, null);
    }

    /**
     * @param list<Page<string>> $pages served in order
     * @param \ArrayObject<int, ?string> $cursors receives the cursor of every fetch
     *
     * @return \Closure(?string): Page<string>
     */
    private function fetcher(array $pages, \ArrayObject $cursors): \Closure
    {
        return static function (?string $after) use (&$pages, $cursors): Page {
            $cursors->append($after);
            $page = array_shift($pages);
            self::assertInstanceOf(Page::class, $page, 'fetched more pages than the test prepared');

            return $page;
        };
    }

    #[Test]
    public function fetchesNothingUntilTheFirstItemIsRequested(): void
    {
        $cursors = new \ArrayObject();

        $items = (new Paginator())->iterate($this->fetcher([$this->page(['a'], null, false)], $cursors), 'p');

        self::assertSame([], $cursors->getArrayCopy());
        self::assertSame('a', $items->current());
        self::assertSame([null], $cursors->getArrayCopy());
    }

    #[Test]
    public function fetchesTheNextPageOnlyAfterThePreviousOneIsConsumed(): void
    {
        $cursors = new \ArrayObject();
        $items = (new Paginator())->iterate($this->fetcher([$this->page(['a', 'b'], 'c2', true), $this->page(['c'], null, false)], $cursors), 'p');

        $items->current();
        $items->next();
        self::assertSame('b', $items->current());
        self::assertSame([null], $cursors->getArrayCopy(), 'last item of page 1: page 2 is not requested yet');

        $items->next();
        self::assertSame('c', $items->current());
        self::assertSame([null, 'c2'], $cursors->getArrayCopy());
    }

    #[Test]
    public function stopsAfterAPageWithoutANextPage(): void
    {
        $cursors = new \ArrayObject();

        $all = iterator_to_array((new Paginator())->iterate($this->fetcher([$this->page(['a'], 'ignored', false)], $cursors), 'p'), true);

        self::assertSame(['a'], $all);
        self::assertSame([null], $cursors->getArrayCopy());
    }

    #[Test]
    public function keysAreSequentialAcrossPagesAndEmptyPagesAreSkipped(): void
    {
        $cursors = new \ArrayObject();
        $pages = [$this->page(['a', 'b'], 'c2', true), $this->page([], 'c3', true), $this->page(['c'], null, false)];

        $all = iterator_to_array((new Paginator())->iterate($this->fetcher($pages, $cursors), 'p'), true);

        self::assertSame([0 => 'a', 1 => 'b', 2 => 'c'], $all);
        self::assertSame([null, 'c2', 'c3'], $cursors->getArrayCopy());
    }

    /**
     * @return iterable<string, array{?string, ?string}> endCursor of page 1, endCursor of page 2
     */
    public static function brokenCursors(): iterable
    {
        yield 'hasNextPage with a null cursor' => ['c1', null];
        yield 'the cursor just used again' => ['c1', 'c1'];
    }

    #[Test]
    #[DataProvider('brokenCursors')]
    public function failsInsteadOfLoopingWhenTheServerGivesNoNewCursor(?string $first, ?string $second): void
    {
        $cursors = new \ArrayObject();
        $items = (new Paginator())->iterate($this->fetcher([$this->page(['a'], $first, true), $this->page(['b'], $second, true)], $cursors), 'some_list');

        try {
            iterator_to_array($items, true);
            self::fail('Expected a DecodeException');
        } catch (DecodeException $e) {
            self::assertSame('some_list.pageInfo.endCursor', $e->path);
            self::assertCount(2, $cursors);
        }
    }

    #[Test]
    public function failsWhenTheFirstPageHasNextButNoCursor(): void
    {
        $cursors = new \ArrayObject();
        $items = (new Paginator())->iterate($this->fetcher([$this->page(['a'], null, true)], $cursors), 'some_list');

        $this->expectException(DecodeException::class);

        iterator_to_array($items, true);
    }
}
