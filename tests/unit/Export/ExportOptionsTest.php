<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Export\ExportOptions;
use WhiteMarket\Sdk\Export\JsonStreamDecoder;
use WhiteMarket\Sdk\Transport\OptionRules;
use WhiteMarket\Sdk\Version;

#[CoversClass(ExportOptions::class)]
#[CoversClass(OptionRules::class)]
final class ExportOptionsTest extends TestCase
{
    #[Test]
    public function hasTheSpecDefaults(): void
    {
        $options = new ExportOptions();

        self::assertSame(['https://export.white.market', 10.0, 60.0, 67_108_864, null, JsonStreamDecoder::Auto], [
            $options->baseUrl,
            $options->connectTimeout,
            $options->idleTimeout,
            $options->maxBodyBytes,
            $options->userAgentSuffix,
            $options->jsonStreamDecoder,
        ]);
    }

    #[Test]
    public function buildsTheUserAgent(): void
    {
        self::assertSame(Version::userAgent(), (new ExportOptions())->userAgent());
        self::assertSame(Version::userAgent() . ' bot/1.0', (new ExportOptions(userAgentSuffix: 'bot/1.0'))->userAgent());
    }

    /**
     * @return iterable<string, array{array{baseUrl?: string, connectTimeout?: float, idleTimeout?: float, maxBodyBytes?: int, userAgentSuffix?: string}}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'plain http' => [['baseUrl' => 'http://export.white.market']];
        yield 'a path' => [['baseUrl' => 'https://export.white.market/v1']];
        yield 'a query' => [['baseUrl' => 'https://export.white.market?x=1']];
        yield 'a final line feed' => [['baseUrl' => "https://export.white.market\n"]];
        yield 'no scheme' => [['baseUrl' => 'export.white.market']];
        yield 'user and password' => [['baseUrl' => 'https://user:pass@export.white.market']];
        yield 'user only' => [['baseUrl' => 'https://user@export.white.market']];
        yield 'zero connect timeout' => [['connectTimeout' => 0.0]];
        yield 'negative idle timeout' => [['idleTimeout' => -1.0]];
        yield 'zero idle timeout' => [['idleTimeout' => 0.0]];
        yield 'zero max body' => [['maxBodyBytes' => 0]];
        yield 'empty user agent suffix' => [['userAgentSuffix' => '']];
        yield 'control byte in the suffix' => [['userAgentSuffix' => "a\r\nb"]];
    }

    /**
     * @param array{baseUrl?: string, connectTimeout?: float, idleTimeout?: float, maxBodyBytes?: int, userAgentSuffix?: string} $arguments
     */
    #[Test]
    #[DataProvider('invalidOptions')]
    public function rejectsInvalidOptions(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ExportOptions(
            $arguments['baseUrl'] ?? ExportOptions::DEFAULT_BASE_URL,
            $arguments['connectTimeout'] ?? 10.0,
            $arguments['idleTimeout'] ?? 60.0,
            $arguments['maxBodyBytes'] ?? 1024,
            $arguments['userAgentSuffix'] ?? null,
        );
    }

    #[Test]
    public function acceptsTheSmallestValidValues(): void
    {
        $options = new ExportOptions('https://export.example.test/', 0.001, 0.001, 1);

        self::assertSame(1, $options->maxBodyBytes);
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
    public function keepsAnAvailableJsonStreamDecoderAndRefusesAnUnavailableOne(JsonStreamDecoder $decoder): void
    {
        if (!$decoder->isAvailable()) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage($decoder->value);
        }

        $options = new ExportOptions(jsonStreamDecoder: $decoder);

        self::assertSame($decoder, $options->jsonStreamDecoder);
    }
}
