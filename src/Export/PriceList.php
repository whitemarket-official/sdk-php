<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

/**
 * The rows of a prices export with lookup by the record key `(market_hash_name, paint_index)` (SDK_SPEC 3.10): keying
 * by name alone picks an arbitrary Doppler phase, whose price can be off by an order of magnitude. Iterates in file
 * order. When the file holds two rows with one key, the first one is found by {@see get()}; both are in
 * {@see allPhases()}.
 *
 * @implements \IteratorAggregate<int, PriceRow>
 */
final readonly class PriceList implements \IteratorAggregate, \Countable
{
    /** @var list<PriceRow> */
    private array $rows;

    /** @var array<string, PriceRow> keyed by name and paint index */
    private array $byKey;

    /** @var array<string, list<PriceRow>> keyed by name */
    private array $byName;

    /**
     * @param iterable<PriceRow> $rows
     */
    public function __construct(iterable $rows)
    {
        $list = [];
        $byKey = [];
        $byName = [];
        foreach ($rows as $row) {
            $list[] = $row;
            $byKey[$this->key($row->marketHashName, $row->paintIndex)] ??= $row;
            $byName[$this->nameKey($row->marketHashName)][] = $row;
        }
        $this->rows = $list;
        $this->byKey = $byKey;
        $this->byName = $byName;
    }

    /**
     * @param string $paintIndex `"0"` for an item without a paint (agents, stickers, cases, plain weapons)
     */
    public function get(string $marketHashName, string $paintIndex = '0'): ?PriceRow
    {
        return $this->byKey[$this->key($marketHashName, $paintIndex)] ?? null;
    }

    /**
     * Every row of the name, one per paint index (the phases of a Doppler), in file order.
     *
     * @return list<PriceRow>
     */
    public function allPhases(string $marketHashName): array
    {
        return $this->byName[$this->nameKey($marketHashName)] ?? [];
    }

    public function count(): int
    {
        return \count($this->rows);
    }

    /**
     * @return \ArrayIterator<int, PriceRow>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->rows);
    }

    /**
     * A NUL separates the parts and cannot be in a market hash name; the prefix keeps a numeric name from becoming an
     * integer array key.
     */
    private function key(string $marketHashName, string $paintIndex): string
    {
        return 'k' . $marketHashName . "\0" . $paintIndex;
    }

    /**
     * The prefix keeps a numeric name from becoming an integer array key.
     */
    private function nameKey(string $marketHashName): string
    {
        return 'n' . $marketHashName;
    }
}
