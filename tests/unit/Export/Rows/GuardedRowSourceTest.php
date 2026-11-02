<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export\Rows;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Export\Rows\GuardedRowSource;
use WhiteMarket\Sdk\Export\Rows\RowSource;
use WhiteMarket\Sdk\Tests\Support\RowSources;

#[CoversClass(GuardedRowSource::class)]
final class GuardedRowSourceTest extends TestCase
{
    #[Test]
    public function closesTheInnerSourceWhenItReportsTheEnd(): void
    {
        $inner = new CountingRowSource(['a', 'b']);
        $source = new GuardedRowSource($inner);

        self::assertSame(['a', 'b'], RowSources::drain($source));

        self::assertSame(1, $inner->closes());
    }

    #[Test]
    public function closesTheInnerSourceOnAFailureAndRethrowsTheSameException(): void
    {
        $failure = new \RuntimeException('the body broke');
        $inner = new CountingRowSource(['a'], failure: $failure);
        $source = new GuardedRowSource($inner);

        [$rows, $caught] = RowSources::collect($source);

        self::assertSame(['a'], $rows);
        self::assertSame($failure, $caught);
        self::assertSame(1, $inner->closes());
    }

    #[Test]
    public function closeIsIdempotentAndClosesTheInnerSourceOnce(): void
    {
        $inner = new CountingRowSource(['a', 'b']);
        $source = new GuardedRowSource($inner);
        $source->next();

        $source->close();
        $source->close();

        self::assertSame(1, $inner->closes());
    }

    #[Test]
    public function closeAfterTheEndDoesNotCloseTheInnerSourceAgain(): void
    {
        $inner = new CountingRowSource([]);
        $source = new GuardedRowSource($inner);
        $source->next();

        $source->close();

        self::assertSame(1, $inner->closes());
    }

    #[Test]
    public function aClosedSourceHasNoRowsAndDoesNotAskTheInnerSource(): void
    {
        $inner = new CountingRowSource(['a', 'b', 'c']);
        $source = new GuardedRowSource($inner);
        self::assertTrue($source->next());
        $source->close();
        $calls = $inner->nexts();

        self::assertFalse($source->next());
        self::assertFalse($source->next());

        self::assertSame($calls, $inner->nexts());
    }

    #[Test]
    public function aSourceThatEndedKeepsAnsweringNoMoreRowsWithoutAskingTheInnerSource(): void
    {
        $inner = new CountingRowSource([]);
        $source = new GuardedRowSource($inner);
        self::assertFalse($source->next());

        self::assertFalse($source->next());

        self::assertSame(1, $inner->nexts());
    }

    #[Test]
    public function theRowIsTheOneOfTheInnerSource(): void
    {
        $source = new GuardedRowSource(new CountingRowSource(['a', 'b']));

        $source->next();
        self::assertSame('a', $source->row());
        $source->next();
        self::assertSame('b', $source->row());
    }
}

/**
 * Yields the given rows, then ends or throws `$failure`; counts the calls it gets.
 */
final class CountingRowSource implements RowSource
{
    private int $nexts = 0;

    private int $closes = 0;

    private int $at = -1;

    /**
     * @param list<string> $rows
     */
    public function __construct(
        private readonly array $rows,
        private readonly ?\Throwable $failure = null,
    ) {}

    public function nexts(): int
    {
        return $this->nexts;
    }

    public function closes(): int
    {
        return $this->closes;
    }

    public function next(): bool
    {
        ++$this->nexts;
        ++$this->at;
        if (\array_key_exists($this->at, $this->rows)) {
            return true;
        }
        if ($this->failure instanceof \Throwable) {
            throw $this->failure;
        }

        return false;
    }

    public function row(): mixed
    {
        return $this->rows[$this->at] ?? null;
    }

    public function close(): void
    {
        ++$this->closes;
    }
}
