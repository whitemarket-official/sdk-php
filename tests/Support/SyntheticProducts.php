<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

/**
 * The bytes of a products export file of any number of synthetic rows, produced lazily in chunks so a file of a million
 * rows is never held in memory (for the memory test and the decoder benchmark). The rows have the shape of the products
 * export: ids as JSON numbers up to the 64-bit limit (some beyond 2^53), prices and floats as decimal strings, nulls, and
 * a key this SDK version does not know on every 1000th row.
 */
final class SyntheticProducts
{
    /** Rows per chunk: about 100 KB of JSON. */
    private const ROWS_PER_CHUNK = 200;

    /** The first class id; every row adds its index. Beyond 2^53, below 2^63. */
    public const CLASS_ID_BASE = 4_611_686_018_427_387_904;

    public const ASSET_ID_BASE = 9_007_199_254_740_993;

    /** Rows the server writes without item data, see docs/WHITEMARKET_API.md section 8 "Null category rows". */
    public const ITEMLESS_NULL_FIELDS = [
        'product_category',
        'product_type',
        'product_subtype',
        'product_float',
        'product_paint_index',
        'product_paint_seed',
        'product_phase',
        'inspectLink',
    ];

    private function __construct() {}

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public static function itemless(array $row): array
    {
        return array_replace($row, array_fill_keys(self::ITEMLESS_NULL_FIELDS, null));
    }

    public static function productId(int $index): string
    {
        return \sprintf('00000000-0000-4000-8000-%012d', $index);
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(int $index, bool $rankOnEveryRow = false): array
    {
        $row = [
            'product_id' => self::productId($index),
            'product_category' => $index % 3 === 0 ? 'WEAPON' : 'STICKERS',
            'product_type' => 'Sticker',
            'product_subtype' => null,
            'product_class_id' => self::CLASS_ID_BASE + $index,
            'product_instance_id' => 0,
            'product_asset_id' => self::ASSET_ID_BASE + $index,
            'product_float' => $index % 2 === 0 ? \sprintf('0.%07d', $index % 10_000_000) : null,
            'product_paint_index' => null,
            'product_phase' => null,
            'name_hash' => 'Sticker | Synthetic ' . $index,
            'price' => \sprintf('%d.%02d', $index % 5000, $index % 100),
            'currency' => 'USD',
            'product_paint_seed' => null,
            'deliveryType' => 'auto',
            'deliveryPromise' => '10min',
            'inspectLink' => null,
        ];
        if ($rankOnEveryRow || $index % 1000 === 0) {
            $row['listing_rank'] = $index;
        }

        return $row;
    }

    /**
     * @param non-negative-int $rows
     *
     * @return \Generator<int, string>
     */
    public static function json(int $rows): \Generator
    {
        yield '[';
        for ($start = 0; $start < $rows; $start += self::ROWS_PER_CHUNK) {
            $chunk = [];
            for ($i = $start, $end = min($rows, $start + self::ROWS_PER_CHUNK); $i < $end; ++$i) {
                $chunk[] = self::encode(self::row($i));
            }
            yield ($start === 0 ? '' : ',') . implode(',', $chunk);
        }
        yield ']';
    }

    /**
     * @param non-negative-int $rows
     *
     * @return \Generator<int, string>
     */
    public static function csv(int $rows): \Generator
    {
        yield implode(',', array_keys(self::row(1, true))) . "\r\n";
        for ($start = 0; $start < $rows; $start += self::ROWS_PER_CHUNK) {
            $lines = '';
            for ($i = $start, $end = min($rows, $start + self::ROWS_PER_CHUNK); $i < $end; ++$i) {
                // CSV has one column set: the unknown column is on every row.
                $lines .= implode(',', array_map(
                    static fn(mixed $value): string => $value === null ? '' : '"' . str_replace('"', '""', \is_scalar($value) ? (string) $value : '') . '"',
                    self::row($i, true),
                )) . "\r\n";
            }
            yield $lines;
        }
    }

    /**
     * Gzip of the chunks, deflated one chunk at a time.
     *
     * @param iterable<string> $chunks
     *
     * @return \Generator<int, string>
     */
    public static function gzip(iterable $chunks): \Generator
    {
        $deflate = self::deflater();
        foreach ($chunks as $chunk) {
            $out = deflate_add($deflate, $chunk, \ZLIB_NO_FLUSH);
            if ($out !== '' && $out !== false) {
                yield $out;
            }
        }
        yield (string) deflate_add($deflate, '', \ZLIB_FINISH);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function encode(array $row): string
    {
        return json_encode($row, \JSON_THROW_ON_ERROR);
    }

    private static function deflater(): \DeflateContext
    {
        $deflate = deflate_init(\ZLIB_ENCODING_GZIP);

        return $deflate === false ? throw new \LogicException('deflate_init failed') : $deflate;
    }
}
