<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\DeliveryWire;
use WhiteMarket\Sdk\Models\ItemCategory;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * Turns the JSON of an export (meta sidecar, prices rows, products rows) into SDK values; the one place that knows the file shapes.
 * A missing or mistyped required key fails with a {@see DecodeException} whose path names the row and field
 * (`prices[3].price`); no server value goes into a message. Keys this version does not know are kept in `extra`.
 *
 * JSON numbers are decoded with `JSON_BIGINT_AS_STRING`: an integer beyond the platform int arrives as a digit
 * string. A float token (`1.0E+19`) in an id field is rejected: the precision is already gone.
 *
 * @internal used by {@see ExportClient}
 */
final class ExportMapper
{
    private const PRICE_KEYS = [
        'market_hash_name', 'price', 'market_product_link', 'market_product_count', 'cheapest_asset_id',
        'cheapest_float', 'paint_index', 'paint_seed', 'inspect_link',
    ];

    private const PRODUCT_KEYS = [
        'product_id', 'product_category', 'product_type', 'product_subtype', 'product_class_id', 'product_instance_id',
        'product_asset_id', 'product_float', 'product_paint_index', 'product_phase', 'name_hash', 'price', 'currency',
        'product_paint_seed', 'deliveryType', 'deliveryPromise', 'inspectLink',
    ];

    private const DATE_FORMAT = 'Y-m-d\TH:i:sp';

    private function __construct() {}

    /**
     * @throws DecodeException
     */
    public static function meta(#[\SensitiveParameter] string $json): ExportMeta
    {
        $data = self::object(self::decodeJson($json, 'meta'), 'meta');

        return new ExportMeta(
            self::string($data, 'file', 'meta'),
            self::date($data, 'data_as_of', 'meta'),
            self::date($data, 'generated_at', 'meta'),
            self::nonNegativeInt($data, 'items', 'meta'),
        );
    }

    /**
     * @return list<PriceRow>
     *
     * @throws DecodeException
     */
    public static function priceRows(#[\SensitiveParameter] string $json): array
    {
        $data = self::decodeJson($json, 'prices');
        if (!\is_array($data) || !array_is_list($data)) {
            throw new DecodeException('prices', 'not a list of rows');
        }
        $rows = [];
        foreach ($data as $index => $row) {
            $rows[] = self::priceRow($row, \sprintf('prices[%d]', $index));
        }

        return $rows;
    }

    /**
     * One row of the products file, from JSON or from CSV: a CSV value is a string (ids, paint index and seed as digit
     * strings), an empty CSV value null.
     *
     * Null for a row whose `product_category` is present and null (JSON null, empty CSV value): the server writes such
     * rows without any item data (WHITEMARKET_API.md 8, null category rows), and the stream skips them.
     * A missing key or a non-string value is still a failure.
     *
     * @throws DecodeException
     */
    public static function productRow(#[\SensitiveParameter] mixed $row, string $path): ?ProductRow
    {
        $row = self::object($row, $path);
        if (\array_key_exists('product_category', $row) && $row['product_category'] === null) {
            return null;
        }
        $deliveryType = self::nullableString($row, 'deliveryType', $path);
        $deliveryPromise = self::nullableString($row, 'deliveryPromise', $path);

        return new ProductRow(
            productId: self::string($row, 'product_id', $path),
            marketHashName: self::string($row, 'name_hash', $path),
            price: new Money(self::decimal($row, 'price', $path), self::enum(Currency::class, self::string($row, 'currency', $path))),
            category: self::enum(ItemCategory::class, self::string($row, 'product_category', $path)),
            type: self::nullableString($row, 'product_type', $path),
            subtype: self::nullableString($row, 'product_subtype', $path),
            classId: self::id($row, 'product_class_id', $path),
            instanceId: self::id($row, 'product_instance_id', $path),
            assetId: self::id($row, 'product_asset_id', $path),
            float: self::nullableDecimal($row, 'product_float', $path),
            paintIndex: self::nullableId($row, 'product_paint_index', $path),
            paintSeed: self::nullableId($row, 'product_paint_seed', $path),
            phase: self::nullableString($row, 'product_phase', $path),
            deliveryType: $deliveryType === null ? null : DeliveryWire::type($deliveryType),
            deliveryPromise: $deliveryPromise === null ? null : DeliveryWire::promise($deliveryPromise),
            inspectLink: self::nonEmptyString($row, 'inspectLink', $path),
            extra: self::extra($row, self::PRODUCT_KEYS),
        );
    }

    /**
     * @throws DecodeException
     */
    private static function priceRow(#[\SensitiveParameter] mixed $row, string $path): PriceRow
    {
        $row = self::object($row, $path);
        $price = self::decimal($row, 'price', $path);

        return new PriceRow(
            marketHashName: self::string($row, 'market_hash_name', $path),
            price: new Money($price, Currency::Usd),
            marketProductLink: self::string($row, 'market_product_link', $path),
            marketProductCount: self::nonNegativeInt($row, 'market_product_count', $path),
            cheapestAssetId: self::id($row, 'cheapest_asset_id', $path),
            cheapestFloat: self::nullableDecimal($row, 'cheapest_float', $path),
            paintIndex: self::string($row, 'paint_index', $path),
            paintSeed: self::string($row, 'paint_seed', $path),
            inspectLink: self::nonEmptyString($row, 'inspect_link', $path),
            extra: self::extra($row, self::PRICE_KEYS),
        );
    }

    /**
     * @throws DecodeException
     */
    private static function decodeJson(#[\SensitiveParameter] string $json, string $path): mixed
    {
        try {
            return json_decode($json, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            // Not chained: its trace holds the json_decode() arguments, the body.
            $invalid = new DecodeException($path, 'not valid JSON');
        }

        throw $invalid;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws DecodeException
     */
    private static function object(#[\SensitiveParameter] mixed $value, string $path): array
    {
        if (!\is_array($value) || array_is_list($value) && $value !== []) {
            throw new DecodeException($path, 'not an object');
        }
        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws DecodeException
     */
    private static function decimal(#[\SensitiveParameter] array $data, string $key, string $path): Decimal
    {
        return Decimal::tryOf(self::string($data, $key, $path))
            ?? throw new DecodeException($path . '.' . $key, 'not a decimal number');
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws DecodeException
     */
    private static function nullableDecimal(#[\SensitiveParameter] array $data, string $key, string $path): ?Decimal
    {
        $value = self::nullableString($data, $key, $path);

        return $value === null ? null : (Decimal::tryOf($value) ?? throw new DecodeException($path . '.' . $key, 'not a decimal number'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function string(#[\SensitiveParameter] array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;

        return \is_string($value) ? $value : throw new DecodeException($path . '.' . $key, 'missing or not a string');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullableString(#[\SensitiveParameter] array $data, string $key, string $path): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null || \is_string($value) ? $value : throw new DecodeException($path . '.' . $key, 'not a string or null');
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nonNegativeInt(#[\SensitiveParameter] array $data, string $key, string $path): int
    {
        $value = $data[$key] ?? null;

        return \is_int($value) && $value >= 0 ? $value : throw new DecodeException($path . '.' . $key, 'missing or not a non-negative integer');
    }

    /**
     * A 64-bit id as a digit string: a JSON integer within the platform int, or the digit string `JSON_BIGINT_AS_STRING`
     * made of a larger one. A float token has lost digits already.
     *
     * @param array<string, mixed> $data
     */
    private static function id(#[\SensitiveParameter] array $data, string $key, string $path): string
    {
        $value = $data[$key] ?? null;
        if (\is_int($value) && $value >= 0) {
            return (string) $value;
        }

        return \is_string($value) && preg_match('/^\d+$/D', $value) === 1 ? $value : throw new DecodeException($path . '.' . $key, 'missing or not an integer');
    }

    /**
     * The string at `$key`, null when it is missing, null or empty.
     *
     * @param array<string, mixed> $data
     *
     * @throws DecodeException
     */
    private static function nonEmptyString(#[\SensitiveParameter] array $data, string $key, string $path): ?string
    {
        $value = self::nullableString($data, $key, $path);

        return $value === '' ? null : $value;
    }

    /**
     * The columns of a row that the SDK does not map.
     *
     * @param array<string, mixed> $row
     * @param list<string> $known
     *
     * @return array<string, mixed>
     */
    private static function extra(#[\SensitiveParameter] array $row, array $known): array
    {
        return array_diff_key($row, array_flip($known));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function nullableId(#[\SensitiveParameter] array $data, string $key, string $path): ?string
    {
        return ($data[$key] ?? null) === null ? null : self::id($data, $key, $path);
    }

    /**
     * @template E of \BackedEnum
     *
     * @param class-string<E> $enum
     *
     * @return E|UnknownEnumValue
     */
    private static function enum(string $enum, #[\SensitiveParameter] string $value): \BackedEnum|UnknownEnumValue
    {
        return $enum::tryFrom($value) ?? new UnknownEnumValue($enum, $value);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function date(#[\SensitiveParameter] array $data, string $key, string $path): \DateTimeImmutable
    {
        $value = self::string($data, $key, $path);
        $date = \DateTimeImmutable::createFromFormat(self::DATE_FORMAT, $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DecodeException($path . '.' . $key, 'not an ISO 8601 date-time');
        }

        return $date;
    }
}
