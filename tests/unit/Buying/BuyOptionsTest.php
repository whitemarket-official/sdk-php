<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Buying;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Buying\BuyOptions;
use WhiteMarket\Sdk\Tests\Support\DebugOutput;

#[CoversClass(BuyOptions::class)]
final class BuyOptionsTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedCustomIds(): iterable
    {
        yield 'one character' => ['a'];
        yield '100 characters' => [str_repeat('a', 100)];
        yield '100 multi-byte characters (more than 100 bytes)' => [str_repeat("\u{0436}", 100)];
        yield 'line breaks are characters too' => ["a\nb"];
    }

    #[Test]
    #[DataProvider('acceptedCustomIds')]
    public function acceptsACustomIdOfOneToHundredCharacters(string $customId): void
    {
        self::assertSame($customId, (new BuyOptions(customId: $customId))->customId);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function rejectedCustomIds(): iterable
    {
        yield 'empty' => [''];
        yield '101 characters' => [str_repeat('a', 101)];
        yield '101 multi-byte characters' => [str_repeat("\u{0436}", 101)];
        yield 'invalid UTF-8' => ["a\xC3"];
    }

    #[Test]
    #[DataProvider('rejectedCustomIds')]
    public function rejectsACustomIdOutsideOneToHundredCharacters(string $customId): void
    {
        $this->expectExceptionObject(new \InvalidArgumentException('customId must be 1 to 100 characters of valid UTF-8.'));

        new BuyOptions(customId: $customId);
    }

    #[Test]
    public function debugOutputHidesTheTradeUrl(): void
    {
        $url = 'https://steamcommunity.com/tradeoffer/new/?partner=1&token=synthetic-trade-token';
        $options = new BuyOptions(customId: 'order-1', tradeUrl: $url);

        $dump = DebugOutput::all($options)['var_dump'];

        self::assertStringNotContainsString('synthetic-trade-token', $dump);
        self::assertStringContainsString('order-1', $dump);
        self::assertSame(['customId' => 'order-1', 'tradeUrl' => '***'], $options->__debugInfo());
        self::assertSame(['customId' => null, 'tradeUrl' => null], (new BuyOptions())->__debugInfo());
        self::assertSame($url, $options->tradeUrl);
    }

    #[Test]
    public function everythingIsOptional(): void
    {
        $options = new BuyOptions();

        self::assertNull($options->customId);
        self::assertNull($options->tradeUrl);
    }
}
