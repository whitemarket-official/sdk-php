<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export;

use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\Psr7\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use WhiteMarket\Sdk\Errors\DecodeException;
use WhiteMarket\Sdk\Errors\GeneralExportException;
use WhiteMarket\Sdk\Errors\GeneralHttpException;
use WhiteMarket\Sdk\Errors\HttpException;
use WhiteMarket\Sdk\Errors\ServerException;
use WhiteMarket\Sdk\Errors\StaleExportException;
use WhiteMarket\Sdk\Errors\TransportException;
use WhiteMarket\Sdk\Export\ExportClient;
use WhiteMarket\Sdk\Export\ExportFormat;
use WhiteMarket\Sdk\Export\ExportKind;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Export\ExportWindow;
use WhiteMarket\Sdk\Export\JsonStreamDecoder;
use WhiteMarket\Sdk\Export\NotModified;
use WhiteMarket\Sdk\Export\PriceList;
use WhiteMarket\Sdk\Export\PriceRow;
use WhiteMarket\Sdk\Export\ProductRow;
use WhiteMarket\Sdk\Tests\Support\ArrayCache;
use WhiteMarket\Sdk\Tests\Support\FakeHttpClient;
use WhiteMarket\Sdk\Tests\Support\FrozenClock;
use WhiteMarket\Sdk\Tests\Support\ManualClock;
use WhiteMarket\Sdk\Tests\Support\SyntheticProducts;
use WhiteMarket\Sdk\Tests\Support\TestExport;
use WhiteMarket\Sdk\Tests\Support\TraceArguments;

#[CoversClass(ExportClient::class)]
#[CoversClass(StaleExportException::class)]
#[CoversClass(GeneralExportException::class)]
final class ExportClientTest extends TestCase
{
    /** @var list<string> */
    private array $cleanup = [];

    protected function tearDown(): void
    {
        foreach ($this->cleanup as $path) {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($path)) {
                foreach ($this->listing($path) as $leftover) {
                    unlink($path . '/' . $leftover);
                }
                rmdir($path);
            }
        }
    }

    /**
     * @return iterable<string, array{ExportKind, int, ExportWindow, ExportFormat, string}>
     */
    public static function files(): iterable
    {
        $base = 'https://export.white.market/v1/';
        yield 'prices, all, json' => [ExportKind::Prices, 730, ExportWindow::All, ExportFormat::Json, $base . 'prices/730.json'];
        yield 'prices, 10 minutes' => [ExportKind::Prices, 730, ExportWindow::Minutes10, ExportFormat::Json, $base . 'prices/730.10min.json'];
        yield 'products, 1 hour, csv' => [ExportKind::Products, 730, ExportWindow::Hour1, ExportFormat::Csv, $base . 'products/730.1hour.csv'];
        yield 'products, 12 hours' => [ExportKind::Products, 730, ExportWindow::Hours12, ExportFormat::Json, $base . 'products/730.12hours.json'];
        yield 'prices, 24 hours, csv' => [ExportKind::Prices, 730, ExportWindow::Hours24, ExportFormat::Csv, $base . 'prices/730.24hours.csv'];
        yield 'another app' => [ExportKind::Prices, 570, ExportWindow::All, ExportFormat::Json, $base . 'prices/570.json'];
        yield 'the largest app id' => [ExportKind::Prices, 2_147_483_647, ExportWindow::All, ExportFormat::Json, $base . 'prices/2147483647.json'];
    }

    #[Test]
    #[DataProvider('files')]
    public function requestsTheMetaSidecarOfTheFile(ExportKind $kind, int $app, ExportWindow $window, ExportFormat $format, string $url): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.meta.json')));

        $meta = TestExport::client($http)->meta($kind, $app, $window, $format);

        self::assertSame($url . '.meta.json', (string) $http->lastRequest()->getUri());
        self::assertSame(4, $meta->items);
    }

    #[Test]
    #[DataProvider('files')]
    public function downloadsTheFileFromItsUrl(ExportKind $kind, int $app, ExportWindow $window, ExportFormat $format, string $url): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'payload'));
        $destination = Utils::streamFor(fopen('php://memory', 'r+'));

        TestExport::client($http)->download($kind, $app, $window, $format, $destination);

        self::assertSame($url, (string) $http->lastRequest()->getUri());
        $destination->rewind();
        self::assertSame('payload', (string) $destination);
    }

    #[Test]
    public function usesTheConfiguredBaseUrl(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.json')));

        TestExport::client($http, new ExportOptions('https://export.example.test/'))->prices();

        self::assertSame('https://export.example.test/v1/prices/730.json', (string) $http->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidApps(): iterable
    {
        foreach (['meta', 'prices', 'streamPrices', 'download'] as $method) {
            yield $method . ', zero' => [$method, 0];
            yield $method . ', negative' => [$method, -730];
            yield $method . ', one past 2^31-1' => [$method, 2_147_483_648];
        }
    }

    #[Test]
    #[DataProvider('invalidApps')]
    public function refusesAnAppThatIsNotASteamAppId(string $method, int $app): void
    {
        $http = new FakeHttpClient();
        $client = TestExport::client($http);

        $this->expectException(\InvalidArgumentException::class);

        try {
            if ($method === 'meta') {
                $client->meta(ExportKind::Prices, $app);
            } elseif ($method === 'prices') {
                $client->prices($app);
            } elseif ($method === 'streamPrices') {
                $client->streamPrices($app);
            } else {
                $client->download(ExportKind::Prices, $app, ExportWindow::All, ExportFormat::Json, Utils::streamFor(''));
            }
        } finally {
            self::assertSame([], $http->requests());
        }
    }

    #[Test]
    public function parsesThePricesFileIntoALookupList(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, (string) gzencode(TestExport::fixture('prices.json')), ['Content-Encoding' => 'gzip']));

        $list = TestExport::client($http)->prices();

        self::assertInstanceOf(PriceList::class, $list);
        self::assertCount(4, $list);
        self::assertSame('9007199254740993', $list->get('AK-47 | Redline (Field-Tested)', '282')?->cheapestAssetId);
        self::assertCount(1, $http->requests());
    }

    #[Test]
    public function streamsTheRowsInFileOrderAndSendsTheRequestAtTheCall(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.json')));

        $rows = TestExport::client($http)->streamPrices(570, ExportWindow::Hour1);

        self::assertSame('https://export.white.market/v1/prices/570.1hour.json', (string) $http->lastRequest()->getUri());
        self::assertSame(
            ['282', '0', '415', '418'],
            array_map(static fn(PriceRow $row): string => $row->paintIndex, iterator_to_array($rows, false)),
        );
    }

    /**
     * @return iterable<string, array{ExportWindow, ExportFormat, string}>
     */
    public static function productFiles(): iterable
    {
        $base = 'https://export.white.market/v1/products/730';
        yield 'all, json' => [ExportWindow::All, ExportFormat::Json, $base . '.json'];
        yield 'all, csv' => [ExportWindow::All, ExportFormat::Csv, $base . '.csv'];
        yield '10 minutes, json' => [ExportWindow::Minutes10, ExportFormat::Json, $base . '.10min.json'];
        yield '24 hours, csv' => [ExportWindow::Hours24, ExportFormat::Csv, $base . '.24hours.csv'];
    }

    #[Test]
    #[DataProvider('productFiles')]
    public function streamsTheProductRowsOfTheRequestedFile(ExportWindow $window, ExportFormat $format, string $url): void
    {
        $body = $format === ExportFormat::Csv ? self::productsCsv(3) : self::productsJson(3);
        $http = new FakeHttpClient(FakeHttpClient::status(200, $body));

        $rows = TestExport::client($http)->streamProducts(730, $window, $format);

        self::assertSame($url, (string) $http->lastRequest()->getUri());
        $products = self::drain($rows);
        self::assertSame([0, 1, 2], array_keys($products));
        self::assertContainsOnlyInstancesOf(ProductRow::class, $products);
        self::assertSame(
            ['00000000-0000-4000-8000-000000000000', '00000000-0000-4000-8000-000000000001', '00000000-0000-4000-8000-000000000002'],
            array_map(static fn(ProductRow $p): string => $p->productId, $products),
        );
        self::assertSame(['1.25', '7.060', '1.25'], array_map(static fn(ProductRow $p): string => $p->price->amount->toString(), $products));
        self::assertSame(['18446744073709551615', '18446744073709551615', '18446744073709551615'], array_map(static fn(ProductRow $p): string => $p->classId, $products));
    }

    #[Test]
    public function aJsonAndACsvFileOfTheSameProductsGiveEqualRows(): void
    {
        $json = self::drain(TestExport::client(new FakeHttpClient(FakeHttpClient::status(200, self::productsJson(3))))->streamProducts());
        $csv = self::drain(TestExport::client(new FakeHttpClient(FakeHttpClient::status(200, self::productsCsv(3))))->streamProducts(format: ExportFormat::Csv));

        // Value equality of the models: var_export() prints the fields, not the object ids.
        self::assertSame(var_export($json, true), var_export($csv, true));
        self::assertCount(3, $csv);
    }

    #[Test]
    public function streamsAGzipProductsFileOfSeveralChunks(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, (string) gzencode(self::productsJson(300)), ['Content-Encoding' => 'gzip']));

        $products = self::drain(TestExport::client($http)->streamProducts());

        self::assertSame(
            array_map(static fn(int $i): string => \sprintf('00000000-0000-4000-8000-%012d', $i), range(0, 299)),
            array_map(static fn(ProductRow $p): string => $p->productId, $products),
        );
    }

    /**
     * @return iterable<string, array{int, class-string<HttpException>}>
     */
    public static function failingStatuses(): iterable
    {
        yield '404' => [404, GeneralHttpException::class];
        yield '500, after the attempts of the retry policy' => [500, ServerException::class];
    }

    /**
     * @param class-string<HttpException> $exception
     */
    #[Test]
    #[DataProvider('failingStatuses')]
    public function streamProductsFailsAtTheCallNotLazilyOnAnErrorStatus(int $status, string $exception): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status($status, 'error body'), FakeHttpClient::status($status, 'error body'), FakeHttpClient::status($status, 'error body'));

        $e = TraceArguments::caught(fn(): \Iterator => TestExport::client($http)->streamProducts());

        self::assertInstanceOf($exception, $e);
    }

    #[Test]
    public function streamProductsSendsTheRequestAtTheCallBeforeAnyIteration(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, self::productsJson(1)));

        TestExport::client($http)->streamProducts(570);

        self::assertSame('https://export.white.market/v1/products/570.json', (string) $http->lastRequest()->getUri());
    }

    /**
     * @return iterable<string, array{int|null, bool}>
     */
    public static function productAges(): iterable
    {
        // data_as_of is 2026-10-30T09:24:02+00:00; the clock starts at 2026-10-30T10:00:00+00:00 (2158 s later).
        yield 'no limit' => [null, true];
        yield 'exactly at the limit' => [2158, true];
        yield 'one second over the limit' => [2157, false];
    }

    #[Test]
    #[DataProvider('productAges')]
    public function streamProductsChecksTheAgeOfTheDataBeforeRequestingTheFile(?int $maxAge, bool $fresh): void
    {
        $queue = $maxAge === null ? [] : [FakeHttpClient::status(200, TestExport::fixture('prices.meta.json'))];
        if ($fresh) {
            $queue[] = FakeHttpClient::status(200, self::productsJson(2));
        }
        $http = new FakeHttpClient(...$queue);
        $client = TestExport::client($http, clock: new ManualClock('2026-10-30T10:00:00+00:00'));

        if ($fresh) {
            $products = self::drain($client->streamProducts(730, ExportWindow::Hour1, ExportFormat::Json, $maxAge));

            self::assertCount(2, $products);
            self::assertStringEndsWith('/products/730.1hour.json', (string) $http->lastRequest()->getUri());
            self::assertCount($maxAge === null ? 1 : 2, $http->requests());

            return;
        }
        $e = TraceArguments::caught(fn(): \Iterator => $client->streamProducts(730, ExportWindow::Hour1, ExportFormat::Json, $maxAge));

        self::assertInstanceOf(StaleExportException::class, $e);
        self::assertSame($maxAge, $e->maxAgeSeconds);
        self::assertCount(1, $http->requests(), 'the file was not requested');
        self::assertStringEndsWith('/products/730.1hour.json.meta.json', (string) $http->lastRequest()->getUri());
    }

    #[Test]
    public function streamProductsRefusesANegativeMaxAgeAndAnInvalidApp(): void
    {
        $http = new FakeHttpClient();
        $client = TestExport::client($http);

        $negative = TraceArguments::caught(fn(): \Iterator => $client->streamProducts(maxAgeSeconds: -1));
        $app = TraceArguments::caught(fn(): \Iterator => $client->streamProducts(0));

        self::assertInstanceOf(\InvalidArgumentException::class, $negative);
        self::assertInstanceOf(\InvalidArgumentException::class, $app);
        self::assertSame([], $http->requests());
    }

    /**
     * @return iterable<string, array{ExportFormat}>
     */
    public static function formats(): iterable
    {
        yield 'json' => [ExportFormat::Json];
        yield 'csv' => [ExportFormat::Csv];
    }

    #[Test]
    #[DataProvider('formats')]
    public function aBadProductRowInTheMiddleFailsAfterTheRowsBeforeIt(ExportFormat $format): void
    {
        $file = [self::productRowData(0), self::productRowData(1), array_replace(self::productRowData(2), ['price' => 'abc']), self::productRowData(3)];
        $body = $format === ExportFormat::Csv ? self::csvOf($file) : (string) json_encode($file);
        $rows = TestExport::client(new FakeHttpClient(FakeHttpClient::status(200, $body)))->streamProducts(format: $format);
        $seen = [];

        $e = TraceArguments::caught(function () use ($rows, &$seen): void {
            foreach ($rows as $product) {
                $seen[] = $product->productId;
            }
        });

        self::assertSame(['00000000-0000-4000-8000-000000000000', '00000000-0000-4000-8000-000000000001'], $seen);
        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[2].price', $e->path);
    }

    #[Test]
    public function aProductsBodyCutShortIsATransportErrorAfterTheCompleteRows(): void
    {
        $body = self::productsJson(2);
        $http = new FakeHttpClient(new Response(200, ['Content-Length' => (string) (\strlen($body) + 100)], $body));
        $rows = TestExport::client($http)->streamProducts();
        $seen = 0;

        $e = TraceArguments::caught(function () use ($rows, &$seen): void {
            foreach ($rows as $index => $ignored) {
                $seen = $index + 1;
            }
        });

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame(2, $seen);
    }

    #[Test]
    public function aProductsBodyThatIsNotAListFailsWithTheFilePath(): void
    {
        $rows = TestExport::client(new FakeHttpClient(FakeHttpClient::status(200, '{"error":"x"}')))->streamProducts();

        $e = TraceArguments::caught(fn(): array => self::drain($rows));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products', $e->path);
    }

    /**
     * @return iterable<string, array{ExportFormat, JsonStreamDecoder}>
     */
    public static function readers(): iterable
    {
        yield from self::jsonReaders();
        yield 'csv' => [ExportFormat::Csv, JsonStreamDecoder::Auto];
    }

    /**
     * @return iterable<string, array{ExportFormat, JsonStreamDecoder}>
     */
    public static function jsonReaders(): iterable
    {
        foreach (JsonStreamDecoder::cases() as $decoder) {
            yield 'json ' . $decoder->value => [ExportFormat::Json, $decoder];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function itemlessRow(int $i): array
    {
        return SyntheticProducts::itemless(self::productRowData($i));
    }

    /**
     * @param list<array<string, mixed>> $file
     *
     * @return \Iterator<int, ProductRow>
     */
    private function streamOf(array $file, ExportFormat $format, JsonStreamDecoder $decoder): \Iterator
    {
        $this->skipUnlessAvailable($decoder);
        $body = $format === ExportFormat::Csv ? self::csvOf($file) : (string) json_encode($file);

        return TestExport::client(new FakeHttpClient(FakeHttpClient::status(200, $body)), new ExportOptions(jsonStreamDecoder: $decoder))
            ->streamProducts(format: $format);
    }

    #[Test]
    #[DataProvider('readers')]
    public function aRowWithANullCategoryIsSkippedAndTheKeysAreTheFileIndex(ExportFormat $format, JsonStreamDecoder $decoder): void
    {
        $rows = $this->streamOf([self::productRowData(0), self::itemlessRow(1), self::productRowData(2)], $format, $decoder);

        $products = self::drain($rows);

        self::assertSame([0, 2], array_keys($products));
        self::assertSame(
            ['00000000-0000-4000-8000-000000000000', '00000000-0000-4000-8000-000000000002'],
            array_map(static fn(ProductRow $p): string => $p->productId, array_values($products)),
        );
    }

    #[Test]
    #[DataProvider('jsonReaders')]
    public function aRowWithoutTheCategoryKeyStillFailsAtItsFileIndex(ExportFormat $format, JsonStreamDecoder $decoder): void
    {
        $broken = self::productRowData(1);
        unset($broken['product_category']);
        $rows = $this->streamOf([self::productRowData(0), $broken], $format, $decoder);

        $e = TraceArguments::caught(static fn(): array => self::drain($rows));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[1].product_category', $e->path);
    }

    #[Test]
    public function aCsvHeaderWithoutTheCategoryColumnFailsAtTheFirstRow(): void
    {
        // A CSV column cannot be missing from one row only: drop it from the header and every row.
        $file = array_map(
            static fn(array $r): array => array_diff_key($r, ['product_category' => true]),
            [self::productRowData(0), self::productRowData(1)],
        );
        $rows = $this->streamOf($file, ExportFormat::Csv, JsonStreamDecoder::Auto);

        $e = TraceArguments::caught(static fn(): array => self::drain($rows));

        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[0].product_category', $e->path);
    }

    #[Test]
    #[DataProvider('readers')]
    public function theErrorPathAfterASkippedRowCountsTheSkippedRow(ExportFormat $format, JsonStreamDecoder $decoder): void
    {
        $file = [self::productRowData(0), self::itemlessRow(1), array_replace(self::productRowData(2), ['price' => 'abc']), self::productRowData(3)];
        $rows = $this->streamOf($file, $format, $decoder);
        $seen = [];

        $e = TraceArguments::caught(function () use ($rows, &$seen): void {
            foreach ($rows as $index => $product) {
                $seen[] = $index;
            }
        });

        self::assertSame([0], $seen);
        self::assertInstanceOf(DecodeException::class, $e);
        self::assertSame('products[2].price', $e->path);
    }

    /**
     * @return iterable<string, array{JsonStreamDecoder}>
     */
    public static function decoders(): iterable
    {
        foreach (JsonStreamDecoder::cases() as $decoder) {
            yield $decoder->value => [$decoder];
        }
    }

    #[Test]
    #[DataProvider('decoders')]
    public function streamProductsReadsTheFileWithTheConfiguredDecoder(JsonStreamDecoder $decoder): void
    {
        $this->skipUnlessAvailable($decoder);
        // Bytes after the array tell the parsers apart: only the batch decoder (simdjson) refuses them, after its rows.
        $http = new FakeHttpClient(FakeHttpClient::status(200, self::productsJson(3) . 'x'));
        $rows = TestExport::client($http, new ExportOptions(jsonStreamDecoder: $decoder))->streamProducts();
        $seen = [];
        $e = null;

        try {
            foreach ($rows as $product) {
                $seen[] = $product->productId;
            }
        } catch (DecodeException $e) {
        }

        if ($decoder->resolve() === JsonStreamDecoder::Simdjson) {
            self::assertInstanceOf(DecodeException::class, $e);
            self::assertSame('products', $e->path);
            self::assertCount(3, $seen); // the rows before the refused bytes still arrive

            return;
        }
        self::assertNull($e);
        self::assertCount(3, $seen);
    }

    #[Test]
    #[DataProvider('decoders')]
    public function aClassIdBeyondTheSignedRangeAsABareNumberIsExactExceptForYajlStream(JsonStreamDecoder $decoder): void
    {
        $this->skipUnlessAvailable($decoder);
        $body = str_replace('"product_class_id":"18446744073709551615"', '"product_class_id":18446744073709551615', self::productsJson(2));
        self::assertStringContainsString('"product_class_id":18446744073709551615', $body);
        $rows = TestExport::client(new FakeHttpClient(FakeHttpClient::status(200, $body)), new ExportOptions(jsonStreamDecoder: $decoder))->streamProducts();

        if ($decoder->resolve() === JsonStreamDecoder::YajlStream) {
            // libyajl turns the integer into a float, which the mapper refuses rather than round.
            $e = TraceArguments::caught(static fn(): array => self::drain($rows));
            self::assertInstanceOf(DecodeException::class, $e);
            self::assertSame('products[0].product_class_id', $e->path);

            return;
        }

        self::assertSame(['18446744073709551615', '18446744073709551615'], array_map(static fn(ProductRow $p): string => $p->classId, self::drain($rows)));
    }

    #[Test]
    public function streamProductsReturnsAnIteratorThatReadsTheBodyOnce(): void
    {
        $rows = TestExport::client(new FakeHttpClient(FakeHttpClient::status(200, self::productsJson(2))))->streamProducts();
        self::drain($rows);

        $e = TraceArguments::caught(static fn(): array => self::drain($rows));

        self::assertInstanceOf(\LogicException::class, $e);
    }

    private function skipUnlessAvailable(JsonStreamDecoder $decoder): void
    {
        if (!$decoder->isAvailable()) {
            self::markTestSkipped('The PHP extension of the ' . $decoder->value . ' decoder is not loaded.');
        }
    }

    /**
     * @param \Iterator<int, ProductRow> $rows
     *
     * @return array<int, ProductRow>
     */
    private static function drain(\Iterator $rows): array
    {
        return iterator_to_array($rows, true);
    }

    /**
     * @return array<string, mixed>
     */
    private static function productRowData(int $i): array
    {
        return [
            'product_id' => \sprintf('00000000-0000-4000-8000-%012d', $i),
            'product_category' => 'STICKERS',
            'product_type' => 'Sticker',
            'product_subtype' => null,
            'product_class_id' => '18446744073709551615',
            'product_instance_id' => 0,
            'product_asset_id' => '9007199254740993',
            'product_float' => $i === 1 ? '0.0700' : null,
            'product_paint_index' => null,
            'product_phase' => null,
            'name_hash' => 'Sticker | Synthetic ' . $i,
            'price' => $i === 1 ? '7.060' : '1.25',
            'currency' => 'USD',
            'product_paint_seed' => null,
            'deliveryType' => 'auto',
            'deliveryPromise' => '10min',
            'inspectLink' => null,
        ];
    }

    private static function productsJson(int $count): string
    {
        return json_encode(array_map(self::productRowData(...), range(0, $count - 1)), \JSON_THROW_ON_ERROR);
    }

    private static function productsCsv(int $count): string
    {
        return self::csvOf(array_map(self::productRowData(...), range(0, $count - 1)));
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private static function csvOf(array $rows): string
    {
        $lines = [implode(',', array_keys($rows[0] ?? []))];
        foreach ($rows as $row) {
            $lines[] = implode(',', array_map(
                static fn(mixed $value): string => $value === null ? '' : '"' . str_replace('"', '""', \is_scalar($value) ? (string) $value : '') . '"',
                $row,
            ));
        }

        return implode("\r\n", $lines) . "\r\n";
    }

    #[Test]
    public function failsOnAPricesFileThatIsNotWellFormed(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, '{"not":"a list"}'));

        $this->expectException(DecodeException::class);

        TestExport::client($http)->prices();
    }

    #[Test]
    public function boundsTheDecodedSizeOfAWholeFileRead(): void
    {
        $body = TestExport::fixture('prices.json');
        $options = new ExportOptions(maxBodyBytes: \strlen($body) - 1);
        $http = new FakeHttpClient(FakeHttpClient::status(200, (string) gzencode($body), ['Content-Encoding' => 'gzip']));

        $this->expectException(TransportException::class);

        TestExport::client($http, $options)->prices();
    }

    #[Test]
    public function readsAFileOfExactlyTheLimit(): void
    {
        $body = TestExport::fixture('prices.json');
        $http = new FakeHttpClient(FakeHttpClient::status(200, $body));

        $list = TestExport::client($http, new ExportOptions(maxBodyBytes: \strlen($body)))->prices();

        self::assertInstanceOf(PriceList::class, $list);
        self::assertCount(4, $list);
    }

    /**
     * @return iterable<string, array{?int, bool}>
     */
    public static function ages(): iterable
    {
        // data_as_of is 2026-10-30T09:24:02+00:00; the clock starts at 2026-10-30T10:00:00+00:00 (2158 s later).
        yield 'no limit' => [null, true];
        yield 'exactly at the limit' => [2158, true];
        yield 'one second over the limit' => [2157, false];
        yield 'well inside the limit' => [86400, true];
        yield 'zero limit with the data already old' => [0, false];
    }

    #[Test]
    #[DataProvider('ages')]
    public function checksTheAgeOfTheDataBeforeDownloadingIt(?int $maxAge, bool $fresh): void
    {
        $queue = $maxAge === null ? [] : [FakeHttpClient::status(200, TestExport::fixture('prices.meta.json'))];
        if ($fresh) {
            $queue[] = FakeHttpClient::status(200, TestExport::fixture('prices.json'));
        }
        $http = new FakeHttpClient(...$queue);
        $client = TestExport::client($http, clock: new ManualClock('2026-10-30T10:00:00+00:00'));
        self::assertSame(2158, (new \DateTimeImmutable('2026-10-30T10:00:00+00:00'))->getTimestamp() - (new \DateTimeImmutable('2026-10-30T09:24:02+00:00'))->getTimestamp());

        if ($fresh) {
            self::assertInstanceOf(PriceList::class, $client->prices(maxAgeSeconds: $maxAge));
            self::assertCount($maxAge === null ? 1 : 2, $http->requests());

            return;
        }
        $e = TraceArguments::caught(fn(): PriceList|NotModified => $client->prices(maxAgeSeconds: $maxAge));

        self::assertInstanceOf(StaleExportException::class, $e);
        self::assertSame($maxAge, $e->maxAgeSeconds);
        self::assertSame('2026-10-30T09:24:02+00:00', $e->dataAsOf->format(\DATE_ATOM));
        self::assertCount(1, $http->requests());
        self::assertStringEndsWith('/prices/730.json.meta.json', (string) $http->lastRequest()->getUri());
    }

    #[Test]
    public function checksTheMetaOfTheSameWindowForStreamPrices(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.meta.json')));
        $client = TestExport::client($http, clock: new ManualClock('2026-10-31T10:00:00+00:00'));

        $this->expectException(StaleExportException::class);

        $client->streamPrices(730, ExportWindow::Minutes10, maxAgeSeconds: 600);
    }

    #[Test]
    public function refusesANegativeMaxAge(): void
    {
        $http = new FakeHttpClient();

        $e = TraceArguments::caught(fn(): PriceList|NotModified => TestExport::client($http)->prices(maxAgeSeconds: -1));

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
        self::assertSame([], $http->requests());
    }

    #[Test]
    public function aConditionalRequestNeedsACache(): void
    {
        $http = new FakeHttpClient();

        $e = TraceArguments::caught(fn(): PriceList|NotModified => TestExport::client($http)->prices(conditional: true));

        self::assertInstanceOf(\InvalidArgumentException::class, $e);
        self::assertSame([], $http->requests());
    }

    #[Test]
    public function returnsNotModifiedWhenTheFileDidNotChange(): void
    {
        $cache = new ArrayCache();
        $http = new FakeHttpClient(
            FakeHttpClient::status(200, TestExport::fixture('prices.json'), ['ETag' => '"v1"']),
            FakeHttpClient::status(304),
        );
        $client = TestExport::client($http, cache: $cache);

        $first = $client->prices(conditional: true);
        $second = $client->prices(conditional: true);

        self::assertInstanceOf(PriceList::class, $first);
        self::assertInstanceOf(NotModified::class, $second);
        self::assertSame('https://export.white.market/v1/prices/730.json', $second->url);
        self::assertSame(['"v1"'], $http->lastRequest()->getHeader('If-None-Match'));
    }

    #[Test]
    public function parsesAChangedFileAndRemembersTheNewValidators(): void
    {
        $cache = new ArrayCache();
        $http = new FakeHttpClient(
            FakeHttpClient::status(200, TestExport::fixture('prices.json'), ['ETag' => '"v1"']),
            FakeHttpClient::status(200, TestExport::fixture('prices.json'), ['ETag' => '"v2"']),
            FakeHttpClient::status(304),
        );
        $client = TestExport::client($http, cache: $cache);

        $client->prices(conditional: true);
        $second = $client->prices(conditional: true);
        $client->prices(conditional: true);

        self::assertInstanceOf(PriceList::class, $second);
        self::assertSame(['"v2"'], $http->lastRequest()->getHeader('If-None-Match'));
    }

    #[Test]
    public function doesNotRememberValidatorsOfABodyItCouldNotParse(): void
    {
        $cache = new ArrayCache();
        $http = new FakeHttpClient(
            FakeHttpClient::status(200, 'not json', ['ETag' => '"broken"']),
            FakeHttpClient::status(200, TestExport::fixture('prices.json')),
        );
        $client = TestExport::client($http, cache: $cache);

        try {
            $client->prices(conditional: true);
            self::fail('the broken body was accepted');
        } catch (DecodeException) {
            // expected
        }
        $list = $client->prices(conditional: true);

        self::assertInstanceOf(PriceList::class, $list);
        self::assertFalse($http->lastRequest()->hasHeader('If-None-Match'));
    }

    #[Test]
    public function anUnconditionalPricesCallNeverRemembersOrSendsValidators(): void
    {
        $cache = new ArrayCache();
        $http = new FakeHttpClient(
            FakeHttpClient::status(200, TestExport::fixture('prices.json'), ['ETag' => '"v1"']),
            FakeHttpClient::status(200, TestExport::fixture('prices.json')),
        );
        $client = TestExport::client($http, cache: $cache);

        $client->prices();
        $client->prices();

        self::assertSame([], $cache->entries());
        self::assertFalse($http->lastRequest()->hasHeader('If-None-Match'));
    }

    #[Test]
    public function mapsAnHttpStatusOfTheMetaToTheHttpHierarchy(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(404, 'not found'));

        $this->expectException(GeneralHttpException::class);

        TestExport::client($http)->meta(ExportKind::Products);
    }

    #[Test]
    public function writesADownloadToAPathAtomically(): void
    {
        $directory = $this->tempDirectory();
        $path = $directory . '/export.csv';
        file_put_contents($path, 'old content');
        $http = new FakeHttpClient(FakeHttpClient::status(200, (string) gzencode(str_repeat('row,1' . "\n", 5000)), ['Content-Encoding' => 'gzip']));

        TestExport::client($http)->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Csv, $path);

        self::assertSame(str_repeat('row,1' . "\n", 5000), file_get_contents($path));
        self::assertSame(['export.csv'], $this->listing($directory), 'no temporary file is left');
    }

    #[Test]
    public function leavesTheDestinationUntouchedAndRemovesTheTemporaryFileWhenTheDownloadFails(): void
    {
        $directory = $this->tempDirectory();
        $path = $directory . '/export.json';
        file_put_contents($path, 'old content');
        // Valid gzip start, cut short: the first chunks are written to the temporary file before the failure shows.
        $truncated = substr((string) gzencode(implode('', array_map(static fn(int $i): string => hash('sha256', (string) $i), range(1, 3000)))), 0, 20000);
        $http = new FakeHttpClient(FakeHttpClient::status(200, $truncated, ['Content-Encoding' => 'gzip']));

        $e = TraceArguments::caught(fn() => TestExport::client($http)->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Json, $path));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('old content', file_get_contents($path));
        self::assertSame(['export.json'], $this->listing($directory), 'no temporary file is left');
    }

    /**
     * @return iterable<string, array{ResponseInterface, class-string<\Throwable>}>
     */
    public static function downloadsThatMustKeepTheOldFile(): iterable
    {
        $body = '0123456789';
        yield '206 with a matching Content-Length (a range, not the file)' => [new Response(206, ['Content-Length' => '10', 'Content-Range' => 'bytes 0-9/1000'], $body), GeneralHttpException::class];
        yield '202' => [new Response(202, ['Content-Length' => '10'], $body), GeneralHttpException::class];
        yield '204' => [new Response(204), GeneralHttpException::class];
        yield 'a duplicated Content-Length, body short' => [new Response(200, ['Content-Length' => ['1000', '1000']], $body), TransportException::class];
        yield 'Content-Length with a plus sign, body short' => [new Response(200, ['Content-Length' => '+1000'], $body), TransportException::class];
        yield 'different Content-Length values' => [new Response(200, ['Content-Length' => '10, 9'], $body), TransportException::class];
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[Test]
    #[DataProvider('downloadsThatMustKeepTheOldFile')]
    public function keepsTheOldFileWhenTheResponseIsNotTheCompleteFile(ResponseInterface $response, string $exception): void
    {
        $directory = $this->tempDirectory();
        $path = $directory . '/export.csv';
        file_put_contents($path, 'old content');

        $e = TraceArguments::caught(fn() => TestExport::client(new FakeHttpClient($response))->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Csv, $path));

        self::assertInstanceOf($exception, $e);
        self::assertSame('old content', file_get_contents($path));
        self::assertSame(['export.csv'], $this->listing($directory));
    }

    #[Test]
    public function downloadsABodyWhoseDuplicatedContentLengthsAgreeWithIt(): void
    {
        $directory = $this->tempDirectory();
        $path = $directory . '/export.csv';
        $http = new FakeHttpClient(new Response(200, ['Content-Length' => ['10', '10']], '0123456789'));

        TestExport::client($http)->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Csv, $path);

        self::assertSame('0123456789', file_get_contents($path));
        self::assertSame(['export.csv'], $this->listing($directory));
    }

    #[Test]
    public function createsNoFileWhenTheServerAnswersAnError(): void
    {
        $directory = $this->tempDirectory();
        $path = $directory . '/export.json';
        $http = new FakeHttpClient(FakeHttpClient::status(404));

        try {
            TestExport::client($http)->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Json, $path);
            self::fail('a 404 was accepted');
        } catch (GeneralHttpException) {
            // expected
        }

        self::assertSame([], $this->listing($directory), 'neither the file nor a temporary one');
    }

    #[Test]
    public function refusesADirectoryAsTheDestination(): void
    {
        $directory = sys_get_temp_dir() . '/wm-export-dir-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $this->cleanup[] = $directory;
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'x'));

        $this->expectException(GeneralExportException::class);

        try {
            TestExport::client($http)->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Json, $directory);
        } finally {
            self::assertSame([], $http->requests());
        }
    }

    #[Test]
    public function reportsADestinationItCannotOpen(): void
    {
        $path = sys_get_temp_dir() . '/wm-export-missing-' . bin2hex(random_bytes(4)) . '/file.json';
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'x'));

        $this->expectException(GeneralExportException::class);

        TestExport::client($http)->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Json, $path);
    }

    #[Test]
    public function writesToAStreamAndLeavesItOpen(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'payload'));
        $stream = Utils::streamFor(fopen('php://memory', 'r+'));

        TestExport::client($http)->download(ExportKind::Prices, 730, ExportWindow::All, ExportFormat::Json, $stream);

        self::assertSame(7, $stream->tell());
        self::assertTrue($stream->isWritable());
    }

    #[Test]
    public function refusesAStreamItCannotWriteTo(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'payload'));
        $stream = Utils::streamFor(fopen('php://memory', 'r'));

        $this->expectException(GeneralExportException::class);

        TestExport::client($http)->download(ExportKind::Prices, 730, ExportWindow::All, ExportFormat::Json, $stream);
    }

    #[Test]
    public function reportsAStreamThatFailsToWrite(): void
    {
        $failing = new class (Utils::tryFopen('php://memory', 'r+')) extends Stream {
            public function write($string): int
            {
                throw new \RuntimeException('synthetic disk full');
            }
        };
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'payload'));

        $this->expectException(GeneralExportException::class);

        TestExport::client($http)->download(ExportKind::Prices, 730, ExportWindow::All, ExportFormat::Json, $failing);
    }

    #[Test]
    public function buildsAffiliateLinksLikeTheStandaloneHelper(): void
    {
        $client = TestExport::client(new FakeHttpClient());

        self::assertSame('https://white.market/x?ref=abc', $client->affiliateLink('https://white.market/x', 'abc'));
        $this->expectException(\InvalidArgumentException::class);
        $client->affiliateLink('https://evil.example.test/x', 'abc');
    }

    #[Test]
    public function createBuildsAStandaloneClientOverAnInjectedHttpClient(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.meta.json')));

        $meta = ExportClient::create(httpClient: $http)->meta(ExportKind::Prices);

        self::assertSame(4, $meta->items);
        self::assertSame('https://export.white.market/v1/prices/730.json.meta.json', (string) $http->lastRequest()->getUri());
    }

    #[Test]
    public function createWithACacheAllowsConditionalRequests(): void
    {
        $http = new FakeHttpClient(FakeHttpClient::status(200, TestExport::fixture('prices.json'), ['ETag' => '"v1"']), FakeHttpClient::status(304));
        $client = ExportClient::create(httpClient: $http, cache: new ArrayCache());

        $client->prices(conditional: true);

        self::assertInstanceOf(NotModified::class, $client->prices(conditional: true));
    }

    #[Test]
    public function aShortWriteIsCompletedNotDropped(): void
    {
        $sink = new class (Utils::tryFopen('php://memory', 'r+')) extends Stream {
            public function write($string): int
            {
                return parent::write(substr($string, 0, 3));
            }
        };
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'payload-payload'));

        TestExport::client($http)->download(ExportKind::Prices, 730, ExportWindow::All, ExportFormat::Json, $sink);

        $sink->rewind();
        self::assertSame('payload-payload', $sink->getContents());
    }

    #[Test]
    public function aWriteThatMakesNoProgressFailsTheDownload(): void
    {
        $stuck = new class (Utils::tryFopen('php://memory', 'r+')) extends Stream {
            public function write($string): int
            {
                return 0;
            }
        };
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'SECRET-PAYLOAD'));

        $e = TraceArguments::caught(fn() => TestExport::client($http)->download(ExportKind::Prices, 730, ExportWindow::All, ExportFormat::Json, $stuck));

        self::assertInstanceOf(GeneralExportException::class, $e);
        self::assertStringNotContainsString('SECRET-PAYLOAD', $e->getMessage());
    }

    #[Test]
    public function aTruncatedIdentityDownloadKeepsTheOldFileAndLeavesNoTemporaryFile(): void
    {
        $directory = $this->tempDirectory();
        $path = $directory . '/export.csv';
        file_put_contents($path, 'old content');
        $http = new FakeHttpClient(new Response(200, ['Content-Length' => '1000'], '0123456789'));

        $e = TraceArguments::caught(fn() => TestExport::client($http)->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Csv, $path));

        self::assertInstanceOf(TransportException::class, $e);
        self::assertSame('old content', file_get_contents($path));
        self::assertSame(['export.csv'], $this->listing($directory));
    }

    #[Test]
    public function aTruncatedMetaIsATransportError(): void
    {
        $http = new FakeHttpClient(new Response(200, ['Content-Length' => '1000'], '{"file":'));

        $this->expectException(TransportException::class);

        TestExport::client($http)->meta(ExportKind::Prices);
    }

    #[Test]
    public function aTruncatedPricesFileIsATransportError(): void
    {
        $body = TestExport::fixture('prices.json');
        $http = new FakeHttpClient(new Response(200, ['Content-Length' => (string) (\strlen($body) + 1)], $body));

        $this->expectException(TransportException::class);

        TestExport::client($http)->prices();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function bodiesWithTrailingData(): iterable
    {
        foreach (['meta', 'prices', 'download'] as $read) {
            yield $read . ', two gzip members' => [gzencode('[]') . gzencode('[]'), $read];
            yield $read . ', garbage after the member' => [gzencode('[]') . 'ZZZZ', $read];
        }
    }

    #[Test]
    #[DataProvider('bodiesWithTrailingData')]
    public function dataAfterTheEndOfTheGzipStreamFailsEveryRead(string $body, string $read): void
    {
        $client = TestExport::client(new FakeHttpClient(new Response(200, ['Content-Encoding' => 'gzip'], $body)));

        $e = TraceArguments::caught(fn() => match ($read) {
            'meta' => $client->meta(ExportKind::Prices),
            'prices' => $client->prices(),
            default => $client->download(ExportKind::Prices, 730, ExportWindow::All, ExportFormat::Json, Utils::streamFor(fopen('php://memory', 'r+'))),
        });

        self::assertInstanceOf(TransportException::class, $e);
        self::assertStringContainsString('data after the end of the gzip stream', $e->getMessage());
    }

    #[Test]
    public function twoDownloadsToOnePathUseDifferentTemporaryFilesInTheDestinationDirectory(): void
    {
        $directory = $this->tempDirectory();
        $path = $directory . '/export.csv';
        $factory = new class implements StreamFactoryInterface {
            /** @var list<string> */
            private array $opened = [];

            /** @var list<int> */
            private array $modes = [];

            private readonly HttpFactory $inner;

            public function __construct()
            {
                $this->inner = new HttpFactory();
            }

            /**
             * @return list<string>
             */
            public function opened(): array
            {
                return $this->opened;
            }

            /**
             * @return list<int>
             */
            public function modes(): array
            {
                return $this->modes;
            }

            public function createStream(string $content = ''): StreamInterface
            {
                return $this->inner->createStream($content);
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                return $this->inner->createStreamFromResource($resource);
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                $stream = $this->inner->createStreamFromFile($filename, $mode);
                $this->opened[] = $filename;
                $this->modes[] = fileperms($filename) & 0777;

                return $stream;
            }
        };
        $http = new FakeHttpClient(FakeHttpClient::status(200, 'one'), FakeHttpClient::status(200, 'two'));
        $options = new ExportOptions();
        $client = new ExportClient(TestExport::transport($http), $factory, $options, new FrozenClock());

        $client->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Csv, $path);
        $client->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Csv, $path);

        self::assertCount(2, array_unique($factory->opened()));
        foreach ($factory->opened() as $temporary) {
            self::assertSame(realpath($directory), realpath(\dirname($temporary)));
            self::assertNotSame($path . '.part', $temporary);
        }
        self::assertSame([0600, 0600], $factory->modes());
        self::assertSame('two', file_get_contents($path));
        self::assertSame(0666 & ~umask(), fileperms($path) & 0777);
        self::assertSame(['export.csv'], $this->listing($directory));
    }

    #[Test]
    public function removesTheTemporaryFileWhenTheDestinationCannotBeOpened(): void
    {
        $directory = $this->tempDirectory();
        $factory = new class implements StreamFactoryInterface {
            public function createStream(string $content = ''): StreamInterface
            {
                return Utils::streamFor($content);
            }

            public function createStreamFromResource($resource): StreamInterface
            {
                return Utils::streamFor($resource);
            }

            public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
            {
                throw new \RuntimeException('synthetic open failure');
            }
        };
        $client = new ExportClient(TestExport::transport(new FakeHttpClient(FakeHttpClient::status(200, 'x'))), $factory, new ExportOptions(), new FrozenClock());

        $e = TraceArguments::caught(fn() => $client->download(ExportKind::Products, 730, ExportWindow::All, ExportFormat::Csv, $directory . '/export.csv'));

        self::assertInstanceOf(GeneralExportException::class, $e);
        self::assertSame([], $this->listing($directory));
    }

    private function tempDirectory(): string
    {
        $directory = sys_get_temp_dir() . '/wm-export-dir-' . bin2hex(random_bytes(4));
        mkdir($directory);
        $this->cleanup[] = $directory;

        return $directory;
    }

    /**
     * @return list<string>
     */
    private function listing(string $directory): array
    {
        $names = [];
        foreach (new \DirectoryIterator($directory) as $entry) {
            if (!$entry->isDot()) {
                $names[] = $entry->getFilename();
            }
        }
        sort($names);

        return $names;
    }
}
