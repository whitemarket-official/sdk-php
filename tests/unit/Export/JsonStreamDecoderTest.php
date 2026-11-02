<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Export\JsonStreamDecoder;
use YajlStream\ArrayParser;

#[CoversClass(JsonStreamDecoder::class)]
final class JsonStreamDecoderTest extends TestCase
{
    /**
     * @return iterable<string, array{JsonStreamDecoder, string}>
     */
    public static function values(): iterable
    {
        yield 'auto' => [JsonStreamDecoder::Auto, 'auto'];
        yield 'json-machine' => [JsonStreamDecoder::JsonMachine, 'json-machine'];
        yield 'simdjson' => [JsonStreamDecoder::Simdjson, 'simdjson'];
        yield 'yajl_stream' => [JsonStreamDecoder::YajlStream, 'yajl_stream'];
    }

    #[Test]
    #[DataProvider('values')]
    public function hasAStableStringValue(JsonStreamDecoder $decoder, string $value): void
    {
        self::assertSame($value, $decoder->value);
        self::assertSame($decoder, JsonStreamDecoder::from($value));
    }

    #[Test]
    public function autoAndJsonMachineAreAlwaysAvailable(): void
    {
        self::assertTrue(JsonStreamDecoder::Auto->isAvailable());
        self::assertTrue(JsonStreamDecoder::JsonMachine->isAvailable());
    }

    #[Test]
    public function simdjsonIsAvailableWhenItsFunctionExists(): void
    {
        self::assertSame(\function_exists('simdjson_decode'), JsonStreamDecoder::Simdjson->isAvailable());
        self::assertSame(\extension_loaded('simdjson'), JsonStreamDecoder::Simdjson->isAvailable());
    }

    #[Test]
    public function yajlStreamIsAvailableWhenItsParserClassExists(): void
    {
        self::assertSame(class_exists(ArrayParser::class), JsonStreamDecoder::YajlStream->isAvailable());
        self::assertSame(\extension_loaded('yajl_stream'), JsonStreamDecoder::YajlStream->isAvailable());
    }

    /**
     * @return iterable<string, array{JsonStreamDecoder}>
     */
    public static function namedDecoders(): iterable
    {
        yield 'json-machine' => [JsonStreamDecoder::JsonMachine];
        yield 'simdjson' => [JsonStreamDecoder::Simdjson];
        yield 'yajl_stream' => [JsonStreamDecoder::YajlStream];
    }

    #[Test]
    #[DataProvider('namedDecoders')]
    public function aNamedDecoderResolvesToItself(JsonStreamDecoder $decoder): void
    {
        self::assertSame($decoder, $decoder->resolve());
    }

    #[Test]
    public function autoResolvesToTheFirstAvailableOfYajlStreamSimdjsonJsonMachine(): void
    {
        $expected = match (true) {
            \extension_loaded('yajl_stream') => JsonStreamDecoder::YajlStream,
            \extension_loaded('simdjson') => JsonStreamDecoder::Simdjson,
            default => JsonStreamDecoder::JsonMachine,
        };

        self::assertSame($expected, JsonStreamDecoder::Auto->resolve());
    }

    #[Test]
    public function autoNeverResolvesToAnUnavailableDecoderOrToAuto(): void
    {
        $resolved = JsonStreamDecoder::Auto->resolve();

        self::assertNotSame(JsonStreamDecoder::Auto, $resolved);
        self::assertTrue($resolved->isAvailable());
    }
}
