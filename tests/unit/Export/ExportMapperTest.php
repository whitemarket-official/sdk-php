<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Export\ExportMapper;
use WhiteMarket\Sdk\Export\ExportMeta;
use WhiteMarket\Sdk\Export\PriceRow;
use WhiteMarket\Sdk\Export\ProductRow;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Models\ItemCategory;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Tests\Support\SyntheticProducts;
use WhiteMarket\Sdk\Tests\Support\TestExport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(ExportMapper::class)]
#[CoversClass(ExportMeta::class)]
#[CoversClass(PriceRow::class)]
#[CoversClass(ProductRow::class)]
final class ExportMapperTest extends TestCase
{
    #[Test]
    public function mapsTheRowsOfAPricesFile(): void
    {
        $rows = ExportMapper::priceRows(TestExport::fixture('prices.json'));

        self::assertCount(4, $rows);
        $first = $rows[0];
        self::assertSame('AK-47 | Redline (Field-Tested)', $first->marketHashName);
        self::assertSame('7.060', $first->price->amount->toString());
        self::assertSame(Currency::Usd, $first->price->currency);
        self::assertSame(12, $first->marketProductCount);
        self::assertSame('0.2517', $first->cheapestFloat?->toString());
        self::assertSame(['282', '661'], [$first->paintIndex, $first->paintSeed]);
        self::assertSame('steam://rungame/730/76561202255233023/+csgo_econ_action_preview%20SYNTHETIC1', $first->inspectLink);
        self::assertSame([], $first->extra);
    }

    #[Test]
    public function keepsIdsBeyondTwoToThe53AsExactStrings(): void
    {
        $rows = ExportMapper::priceRows(TestExport::fixture('prices.json'));

        self::assertSame(
            ['9007199254740993', '18446744073709551615', '30000000000000001', '30000000000000002'],
            array_map(static fn(PriceRow $row): string => $row->cheapestAssetId, $rows),
        );
    }

    #[Test]
    public function mapsANullFloatAnEmptyInspectLinkAndKeepsUnknownKeys(): void
    {
        $rows = ExportMapper::priceRows(TestExport::fixture('prices.json'));
        self::assertArrayHasKey(1, $rows);
        $row = $rows[1];

        self::assertNull($row->cheapestFloat);
        self::assertNull($row->inspectLink);
        self::assertSame(['new_field' => ['nested' => [1, 2]]], $row->extra);
    }

    #[Test]
    public function aMissingNullableKeyIsNull(): void
    {
        $rows = ExportMapper::priceRows($this->rowWith(static function (array $row): array {
            unset($row['cheapest_float'], $row['inspect_link']);

            return $row;
        }));

        self::assertCount(1, $rows);
        self::assertNull($rows[0]->cheapestFloat);
        self::assertNull($rows[0]->inspectLink);
    }

    #[Test]
    public function anEmptyFileHasNoRows(): void
    {
        self::assertSame([], ExportMapper::priceRows('[]'));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenAssetIds(): iterable
    {
        yield 'a float token (precision already lost)' => ['1.0E+19', 'prices[0].cheapest_asset_id'];
        yield 'a decimal fraction' => ['5.5', 'prices[0].cheapest_asset_id'];
        yield 'null' => ['null', 'prices[0].cheapest_asset_id'];
        yield 'a negative id' => ['-5', 'prices[0].cheapest_asset_id'];
        yield 'a non-numeric string' => ['"12ab"', 'prices[0].cheapest_asset_id'];
    }

    #[Test]
    #[DataProvider('brokenAssetIds')]
    public function rejectsAnAssetIdThatIsNotAnExactInteger(string $token, string $path): void
    {
        $json = (string) preg_replace('/"cheapest_asset_id":\d+/', '"cheapest_asset_id":' . $token, TestExport::fixture('prices.json'), 1);

        $e = TraceArguments::caught(fn(): array => ExportMapper::priceRows($json));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function brokenFields(): iterable
    {
        yield 'name missing' => ['market_hash_name', null, 'prices[0].market_hash_name'];
        yield 'name a number' => ['market_hash_name', 5, 'prices[0].market_hash_name'];
        yield 'price missing' => ['price', null, 'prices[0].price'];
        yield 'price a JSON number' => ['price', 7.06, 'prices[0].price'];
        yield 'price with an exponent' => ['price', '7e1', 'prices[0].price'];
        yield 'price empty' => ['price', '', 'prices[0].price'];
        yield 'link missing' => ['market_product_link', null, 'prices[0].market_product_link'];
        yield 'count a string' => ['market_product_count', '12', 'prices[0].market_product_count'];
        yield 'count negative' => ['market_product_count', -1, 'prices[0].market_product_count'];
        yield 'count a float' => ['market_product_count', 1.5, 'prices[0].market_product_count'];
        yield 'float a JSON number' => ['cheapest_float', 0.25, 'prices[0].cheapest_float'];
        yield 'float not a decimal' => ['cheapest_float', 'abc', 'prices[0].cheapest_float'];
        yield 'paint index a number' => ['paint_index', 282, 'prices[0].paint_index'];
        yield 'paint seed missing' => ['paint_seed', null, 'prices[0].paint_seed'];
        yield 'inspect link a number' => ['inspect_link', 5, 'prices[0].inspect_link'];
    }

    #[Test]
    #[DataProvider('brokenFields')]
    public function namesTheFieldOfARowThatBreaksTheShape(string $field, mixed $value, string $path): void
    {
        $json = $this->rowWith(static function (array $row) use ($field, $value): array {
            if ($value === null) {
                unset($row[$field]);
            } else {
                $row[$field] = $value;
            }

            return $row;
        });

        $e = TraceArguments::caught(fn(): array => ExportMapper::priceRows($json));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
    }

    #[Test]
    public function namesTheIndexOfTheBrokenRow(): void
    {
        $e = TraceArguments::caught(fn(): array => ExportMapper::priceRows('[' . substr(TestExport::fixture('prices.json'), 1, -2) . ',{"market_hash_name":"x"}]'));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('prices[4].price', $e->path);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function notAListOfRows(): iterable
    {
        yield 'invalid JSON' => ['[{'];
        yield 'empty body' => [''];
        yield 'an object' => ['{"a":1}'];
        yield 'a scalar' => ['"x"'];
        yield 'a row that is a list' => ['[[1,2]]'];
        yield 'a row that is a scalar' => ['[5]'];
    }

    #[Test]
    #[DataProvider('notAListOfRows')]
    public function rejectsAFileThatIsNotAListOfRowObjects(string $json): void
    {
        $this->expectException(DecodeException::class);

        ExportMapper::priceRows($json);
    }

    #[Test]
    public function doesNotPutServerValuesInTheMessage(): void
    {
        $json = $this->rowWith(static fn(array $row): array => ['price' => 'SERVER-SECRET-VALUE'] + $row);

        $e = TraceArguments::caught(fn(): array => ExportMapper::priceRows($json));

        self::assertStringNotContainsString('SERVER-SECRET-VALUE', $e->getMessage());
    }

    #[Test]
    public function mapsTheMetaSidecar(): void
    {
        $meta = ExportMapper::meta(TestExport::fixture('prices.meta.json'));

        self::assertSame('730.json', $meta->file);
        self::assertSame('2026-10-30T09:24:02+00:00', $meta->dataAsOf->format(\DATE_ATOM));
        self::assertSame('2026-10-30T09:25:37+00:00', $meta->generatedAt->format(\DATE_ATOM));
        self::assertSame(4, $meta->items);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function metaDates(): iterable
    {
        yield 'Z suffix' => ['2026-10-30T09:24:02Z', '2026-10-30T09:24:02+00:00'];
        yield 'a UTC offset' => ['2026-10-30T11:24:02+02:00', '2026-10-30T11:24:02+02:00'];
    }

    #[Test]
    #[DataProvider('metaDates')]
    public function readsTheDatesOfTheMeta(string $given, string $expected): void
    {
        $json = (string) json_encode(['file' => '730.json', 'data_as_of' => $given, 'generated_at' => $given, 'items' => 0]);

        self::assertSame($expected, ExportMapper::meta($json)->dataAsOf->format(\DATE_ATOM));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function brokenMetas(): iterable
    {
        yield 'invalid JSON' => ['{', 'meta'];
        yield 'a list' => ['[1]', 'meta'];
        yield 'file missing' => ['{"data_as_of":"2026-10-30T09:24:02+00:00","generated_at":"2026-10-30T09:24:02+00:00","items":1}', 'meta.file'];
        yield 'date missing' => ['{"file":"a","generated_at":"2026-10-30T09:24:02+00:00","items":1}', 'meta.data_as_of'];
        yield 'date not ISO' => ['{"file":"a","data_as_of":"yesterday","generated_at":"2026-10-30T09:24:02+00:00","items":1}', 'meta.data_as_of'];
        yield 'impossible date' => ['{"file":"a","data_as_of":"2026-02-31T09:24:02+00:00","generated_at":"2026-10-30T09:24:02+00:00","items":1}', 'meta.data_as_of'];
        yield 'date without an offset' => ['{"file":"a","data_as_of":"2026-10-30T09:24:02","generated_at":"2026-10-30T09:24:02+00:00","items":1}', 'meta.data_as_of'];
        yield 'generated_at a number' => ['{"file":"a","data_as_of":"2026-10-30T09:24:02+00:00","generated_at":5,"items":1}', 'meta.generated_at'];
        yield 'items a string' => ['{"file":"a","data_as_of":"2026-10-30T09:24:02+00:00","generated_at":"2026-10-30T09:24:02+00:00","items":"1"}', 'meta.items'];
        yield 'items negative' => ['{"file":"a","data_as_of":"2026-10-30T09:24:02+00:00","generated_at":"2026-10-30T09:24:02+00:00","items":-1}', 'meta.items'];
        yield 'items beyond int' => ['{"file":"a","data_as_of":"2026-10-30T09:24:02+00:00","generated_at":"2026-10-30T09:24:02+00:00","items":99999999999999999999}', 'meta.items'];
    }

    #[Test]
    #[DataProvider('brokenMetas')]
    public function rejectsAMetaThatBreaksTheShape(string $json, string $path): void
    {
        $e = TraceArguments::caught(fn(): mixed => ExportMapper::meta($json));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
    }

    /**
     * A synthetic JSON row of the products file (numbers as the file writes them).
     *
     * @return array<string, mixed>
     */
    private static function jsonProduct(): array
    {
        return [
            'product_id' => '00000000-0000-4000-8000-000000000001',
            'product_category' => 'GLOVES',
            'product_type' => 'Hand Wraps',
            'product_subtype' => 'Slaughter',
            'product_class_id' => '18446744073709551615',
            'product_instance_id' => 188530139,
            'product_asset_id' => '9007199254740993',
            'product_float' => '0.2517',
            'product_paint_index' => 282,
            'product_phase' => 'Phase 2',
            'name_hash' => 'Synthetic Gloves | Test (Field-Tested)',
            'price' => '7.060',
            'currency' => 'USD',
            'product_paint_seed' => 661,
            'deliveryType' => 'semi',
            'deliveryPromise' => '10min',
            'inspectLink' => 'steam://rungame/730/1/+csgo_econ_action_preview%20SYNTHETIC1',
        ];
    }

    /**
     * The same row as a CSV file gives it: every value a string, an empty value null.
     *
     * @return array<string, string|null>
     */
    private static function csvProduct(): array
    {
        return [
            'product_id' => '00000000-0000-4000-8000-000000000001',
            'product_category' => 'GLOVES',
            'product_type' => 'Hand Wraps',
            'product_subtype' => 'Slaughter',
            'product_class_id' => '18446744073709551615',
            'product_instance_id' => '188530139',
            'product_asset_id' => '9007199254740993',
            'product_float' => '0.2517',
            'product_paint_index' => '282',
            'product_phase' => 'Phase 2',
            'name_hash' => 'Synthetic Gloves | Test (Field-Tested)',
            'price' => '7.060',
            'currency' => 'USD',
            'product_paint_seed' => '661',
            'deliveryType' => 'semi',
            'deliveryPromise' => '10min',
            'inspectLink' => 'steam://rungame/730/1/+csgo_econ_action_preview%20SYNTHETIC1',
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapped(array $row, string $path = 'products[0]'): ProductRow
    {
        $product = ExportMapper::productRow($row, $path);
        self::assertInstanceOf(ProductRow::class, $product);

        return $product;
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function happyProductRows(): iterable
    {
        yield 'a JSON row' => [self::jsonProduct()];
        yield 'a CSV row' => [self::csvProduct()];
    }

    /**
     * @param array<string, mixed> $row
     */
    #[Test]
    #[DataProvider('happyProductRows')]
    public function mapsEveryFieldOfAProductRow(array $row): void
    {
        $product = $this->mapped($row, 'products[0]');

        self::assertSame('00000000-0000-4000-8000-000000000001', $product->productId);
        self::assertSame('Synthetic Gloves | Test (Field-Tested)', $product->marketHashName);
        self::assertSame('7.060', $product->price->amount->toString());
        self::assertSame(Currency::Usd, $product->price->currency);
        self::assertSame(ItemCategory::Gloves, $product->category);
        self::assertSame(['Hand Wraps', 'Slaughter', 'Phase 2'], [$product->type, $product->subtype, $product->phase]);
        self::assertSame(['18446744073709551615', '188530139', '9007199254740993'], [$product->classId, $product->instanceId, $product->assetId]);
        self::assertSame('0.2517', $product->float?->toString());
        self::assertSame(['282', '661'], [$product->paintIndex, $product->paintSeed]);
        self::assertSame(DeliveryType::Semi, $product->deliveryType);
        self::assertSame(DeliveryPromise::Minutes10, $product->deliveryPromise);
        self::assertSame('steam://rungame/730/1/+csgo_econ_action_preview%20SYNTHETIC1', $product->inspectLink);
        self::assertSame([], $product->extra);
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function bigIds(): iterable
    {
        yield 'a small int' => [5, '5'];
        yield 'zero' => [0, '0'];
        yield '2^53 + 1 as a digit string' => ['9007199254740993', '9007199254740993'];
        yield 'the largest signed 64-bit id' => [\PHP_INT_MAX, '9223372036854775807'];
        yield 'beyond 2^63 as a digit string' => ['9223372036854775808', '9223372036854775808'];
        yield 'beyond 2^64 as a digit string' => ['18446744073709551616', '18446744073709551616'];
    }

    #[Test]
    #[DataProvider('bigIds')]
    public function keepsEveryIdOfAProductRowAsAnExactDigitString(mixed $given, string $expected): void
    {
        $row = ['product_class_id' => $given, 'product_instance_id' => $given, 'product_asset_id' => $given, 'product_paint_index' => $given, 'product_paint_seed' => $given] + self::jsonProduct();

        $product = $this->mapped($row, 'products[0]');

        self::assertSame([$expected, $expected, $expected, $expected, $expected], [$product->classId, $product->instanceId, $product->assetId, $product->paintIndex, $product->paintSeed]);
    }

    #[Test]
    public function decodesAnIdBeyondTheSignedSixtyFourBitRangeFromJsonAsADigitString(): void
    {
        $json = '{"product_class_id":18446744073709551616,"product_asset_id":9007199254740993,"product_instance_id":1}';
        $row = json_decode($json, true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        self::assertIsArray($row);

        $product = $this->mapped($row + self::jsonProduct(), 'products[0]');

        self::assertSame(['18446744073709551616', '9007199254740993'], [$product->classId, $product->assetId]);
    }

    /**
     * @return iterable<string, array{string, DeliveryType}>
     */
    public static function deliveryTypes(): iterable
    {
        yield 'auto' => ['auto', DeliveryType::Auto];
        yield 'semi' => ['semi', DeliveryType::Semi];
        yield 'manual' => ['manual', DeliveryType::Manual];
        yield 'already upper-case' => ['AUTO', DeliveryType::Auto];
    }

    #[Test]
    #[DataProvider('deliveryTypes')]
    public function mapsTheDeliveryType(string $given, DeliveryType $expected): void
    {
        $product = $this->mapped(['deliveryType' => $given] + self::jsonProduct(), 'products[0]');

        self::assertSame($expected, $product->deliveryType);
    }

    /**
     * @return iterable<string, array{string, DeliveryPromise}>
     */
    public static function deliveryPromises(): iterable
    {
        yield '10min' => ['10min', DeliveryPromise::Minutes10];
        yield '1hour' => ['1hour', DeliveryPromise::Hour1];
        yield '12hours' => ['12hours', DeliveryPromise::Hours12];
        yield '24hours' => ['24hours', DeliveryPromise::Hours24];
    }

    #[Test]
    #[DataProvider('deliveryPromises')]
    public function mapsTheDeliveryPromiseFromTheWindowSuffix(string $given, DeliveryPromise $expected): void
    {
        $product = $this->mapped(['deliveryPromise' => $given] + self::jsonProduct(), 'products[0]');

        self::assertSame($expected, $product->deliveryPromise);
    }

    /**
     * @return iterable<string, array{string, string, class-string<\BackedEnum>, string}>
     */
    public static function unknownValues(): iterable
    {
        yield 'currency EUR' => ['currency', 'EUR', Currency::class, 'EUR'];
        yield 'a new category' => ['product_category', 'NEW_THING', ItemCategory::class, 'NEW_THING'];
        yield 'a lower-case category is not a known value' => ['product_category', 'gloves', ItemCategory::class, 'gloves'];
        yield 'an unknown delivery type, raw value upper-cased' => ['deliveryType', 'drone', DeliveryType::class, 'DRONE'];
        yield 'an unknown delivery promise, raw value as written' => ['deliveryPromise', '5min', DeliveryPromise::class, '5min'];
        yield 'the schema spelling of a promise is not the file spelling' => ['deliveryPromise', 'MINUTES10', DeliveryPromise::class, 'MINUTES10'];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     */
    #[Test]
    #[DataProvider('unknownValues')]
    public function anUnknownEnumValueOfAProductRowIsKeptNotRejected(string $key, string $given, string $enum, string $raw): void
    {
        $product = $this->mapped([$key => $given] + self::jsonProduct(), 'products[0]');

        $value = match ($key) {
            'currency' => $product->price->currency,
            'product_category' => $product->category,
            'deliveryType' => $product->deliveryType,
            default => $product->deliveryPromise,
        };
        self::assertInstanceOf(UnknownEnumValue::class, $value);
        self::assertSame([$enum, $raw], [$value->enum, $value->value]);
    }

    /**
     * @return array<string, mixed>
     */
    private function itemlessProduct(): array
    {
        return SyntheticProducts::itemless(self::jsonProduct());
    }

    #[Test]
    public function aRowWhoseCategoryIsNullMapsToNull(): void
    {
        self::assertNull(ExportMapper::productRow($this->itemlessProduct(), 'products[0]'));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonNullBadCategories(): iterable
    {
        yield 'a number' => [5];
        yield 'a list' => [[]];
        yield 'a boolean' => [false];
    }

    #[Test]
    #[DataProvider('nonNullBadCategories')]
    public function aCategoryThatIsNotAStringOrNullStillFails(mixed $category): void
    {
        $e = TraceArguments::caught(fn(): ?ProductRow => ExportMapper::productRow(['product_category' => $category] + self::jsonProduct(), 'products[3]'));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[3].product_category', $e->path);
    }

    #[Test]
    public function nullableFieldsOfAProductRowMayBeNull(): void
    {
        $nulls = array_fill_keys(
            ['product_float', 'product_paint_index', 'product_phase', 'product_type', 'product_subtype', 'product_paint_seed', 'deliveryType', 'deliveryPromise', 'inspectLink'],
            null,
        );

        $product = $this->mapped($nulls + self::jsonProduct(), 'products[0]');

        self::assertSame([null, null, null, null, null, null, null, null, null], [
            $product->float, $product->paintIndex, $product->phase, $product->type, $product->subtype,
            $product->paintSeed, $product->deliveryType, $product->deliveryPromise, $product->inspectLink,
        ]);
    }

    #[Test]
    public function missingNullableKeysOfAProductRowAreNull(): void
    {
        $row = self::jsonProduct();
        unset($row['product_float'], $row['product_paint_index'], $row['product_paint_seed'], $row['deliveryType'], $row['deliveryPromise'], $row['inspectLink'], $row['product_phase'], $row['product_type'], $row['product_subtype']);

        $product = $this->mapped($row, 'products[0]');

        self::assertNull($product->float);
        self::assertNull($product->paintIndex);
        self::assertNull($product->deliveryType);
        self::assertNull($product->deliveryPromise);
        self::assertNull($product->inspectLink);
    }

    #[Test]
    public function anEmptyInspectLinkIsNull(): void
    {
        $product = $this->mapped(['inspectLink' => ''] + self::jsonProduct(), 'products[0]');

        self::assertNull($product->inspectLink);
    }

    #[Test]
    public function unknownKeysOfAProductRowGoToExtraAndKnownKeysDoNot(): void
    {
        $row = self::jsonProduct() + ['new_field' => ['nested' => [1, 2]], 'another' => null];

        $product = $this->mapped($row, 'products[0]');

        self::assertSame(['new_field' => ['nested' => [1, 2]], 'another' => null], $product->extra);
    }

    /**
     * @return iterable<string, array{string, mixed, string}>
     */
    public static function brokenProductFields(): iterable
    {
        yield 'product_id missing' => ['product_id', null, 'products[3].product_id'];
        yield 'product_id a number' => ['product_id', 5, 'products[3].product_id'];
        yield 'name_hash missing' => ['name_hash', null, 'products[3].name_hash'];
        yield 'product_category missing' => ['product_category', null, 'products[3].product_category'];
        yield 'currency missing' => ['currency', null, 'products[3].currency'];
        yield 'price missing' => ['price', null, 'products[3].price'];
        yield 'price not a decimal' => ['price', 'abc', 'products[3].price'];
        yield 'price a JSON number' => ['price', 7.06, 'products[3].price'];
        yield 'price with an exponent' => ['price', '7e1', 'products[3].price'];
        yield 'price empty' => ['price', '', 'products[3].price'];
        yield 'class id a float token' => ['product_class_id', 1.0E+19, 'products[3].product_class_id'];
        yield 'class id missing' => ['product_class_id', null, 'products[3].product_class_id'];
        yield 'class id negative' => ['product_class_id', -5, 'products[3].product_class_id'];
        yield 'class id a negative digit string' => ['product_class_id', '-5', 'products[3].product_class_id'];
        yield 'class id with letters' => ['product_class_id', '12ab', 'products[3].product_class_id'];
        yield 'instance id empty' => ['product_instance_id', '', 'products[3].product_instance_id'];
        yield 'asset id a fraction' => ['product_asset_id', 5.5, 'products[3].product_asset_id'];
        yield 'paint index a float' => ['product_paint_index', 1.5, 'products[3].product_paint_index'];
        yield 'paint seed negative' => ['product_paint_seed', -1, 'products[3].product_paint_seed'];
        yield 'float not a decimal' => ['product_float', 'abc', 'products[3].product_float'];
        yield 'float a JSON number' => ['product_float', 0.25, 'products[3].product_float'];
        yield 'phase a number' => ['product_phase', 2, 'products[3].product_phase'];
        yield 'type a number' => ['product_type', 2, 'products[3].product_type'];
        yield 'delivery type a number' => ['deliveryType', 1, 'products[3].deliveryType'];
        yield 'delivery promise a number' => ['deliveryPromise', 10, 'products[3].deliveryPromise'];
        yield 'inspect link a number' => ['inspectLink', 5, 'products[3].inspectLink'];
    }

    #[Test]
    #[DataProvider('brokenProductFields')]
    public function namesTheFieldOfAProductRowThatBreaksTheShape(string $key, mixed $value, string $path): void
    {
        $row = $value === null ? array_diff_key(self::jsonProduct(), [$key => true]) : [$key => $value] + self::jsonProduct();

        $e = TraceArguments::caught(fn(): ?ProductRow => ExportMapper::productRow($row, 'products[3]'));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame($path, $e->path);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function notProductObjects(): iterable
    {
        yield 'a scalar' => [5];
        yield 'a string' => ['x'];
        yield 'null' => [null];
        yield 'a non-empty list' => [[1, 2]];
    }

    #[Test]
    #[DataProvider('notProductObjects')]
    public function aProductRowThatIsNotAnObjectFailsAtItsOwnPath(mixed $row): void
    {
        $e = TraceArguments::caught(fn(): ?ProductRow => ExportMapper::productRow($row, 'products[3]'));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[3]', $e->path);
    }

    #[Test]
    public function aProductRowErrorNeverQuotesTheServerValue(): void
    {
        $e = TraceArguments::caught(fn(): ?ProductRow => ExportMapper::productRow(['price' => 'SERVER-SECRET-VALUE'] + self::jsonProduct(), 'products[3]'));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertStringNotContainsString('SERVER-SECRET-VALUE', $e->getMessage());
    }

    /**
     * The first row of the fixture, changed by `$change`, as a one-row file.
     *
     * @param \Closure(array<string, mixed>): array<string, mixed> $change
     */
    private function rowWith(\Closure $change): string
    {
        $rows = json_decode(TestExport::fixture('prices.json'), true, 512, \JSON_THROW_ON_ERROR | \JSON_BIGINT_AS_STRING);
        self::assertIsArray($rows);
        self::assertArrayHasKey(0, $rows);
        $row = $rows[0];
        self::assertIsArray($row);
        /** @var array<string, mixed> $row */

        return json_encode([$change($row)], \JSON_THROW_ON_ERROR);
    }
}
