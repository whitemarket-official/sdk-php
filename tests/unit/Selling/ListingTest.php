<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Selling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\Currency;
use WhiteMarket\Sdk\Models\Decimal;
use WhiteMarket\Sdk\Models\Money;
use WhiteMarket\Sdk\Models\UnknownEnumValue;
use WhiteMarket\Sdk\Selling\ItemReference;
use WhiteMarket\Sdk\Selling\Listing;
use WhiteMarket\Sdk\Selling\PriceChange;

#[CoversClass(Listing::class)]
#[CoversClass(PriceChange::class)]
#[CoversClass(ItemReference::class)]
final class ListingTest extends TestCase
{
    private const UUID = '7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a13';

    /**
     * @return iterable<string, array{string, bool}> reference, accepted
     */
    public static function references(): iterable
    {
        yield 'a UUID' => [self::UUID, true];
        yield 'an upper-case UUID' => [strtoupper(self::UUID), true];
        yield 'a short asset id' => ['1', true];
        yield 'a 20-digit asset id' => ['40000000000000000002', true];
        yield 'a 21-digit asset id' => ['400000000000000000021', false];
        yield 'empty' => ['', false];
        yield 'a leading space' => [' 123', false];
        yield 'a trailing newline on digits' => ["123\n", false];
        yield 'a UUID with a trailing newline' => [self::UUID . "\n", false];
        yield 'a UUID with a leading space' => [' ' . self::UUID, false];
        yield 'a UUID one character short' => ['7d1c0e52-3b8a-4f6d-9a21-5e4c8b7f0a1', false];
        yield 'a signed number' => ['-5', false];
        yield 'a decimal' => ['1.5', false];
        yield 'a hex id' => ['0x1f', false];
        yield 'a name' => ['AK-47 | Redline', false];
    }

    #[Test]
    #[DataProvider('references')]
    public function aListingTakesAnInventoryIdOrAnAssetIdOnly(string $item, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }

        $listing = new Listing($item, Money::usd('5.00'));

        self::assertSame($item, $listing->item);
    }

    #[Test]
    #[DataProvider('references')]
    public function aPriceChangeTakesAListingIdOrAnAssetIdOnly(string $product, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
        }

        $change = new PriceChange($product, Money::usd('5.00'));

        self::assertSame($product, $change->product);
    }

    #[Test]
    public function theMessageNamesTheKindOfReferenceExpectedAndCutsALongValue(): void
    {
        try {
            new Listing(str_repeat('x', 100) . "\n", Money::usd('5.00'));
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('The item must be an inventory id (UUID) or a Steam asset id (digits), got "' . str_repeat('x', 40) . '...".', $e->getMessage());
        }
        try {
            new PriceChange("a\nb", Money::usd('5.00'));
            self::fail('Expected an InvalidArgumentException');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('The product must be a listing id (UUID) or a Steam asset id (digits), got "a\x0ab".', $e->getMessage());
        }
    }

    #[Test]
    public function aListingRefusesAPriceInACurrencyTheSdkCannotSend(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown currency "EUR" cannot be sent.');

        new Listing(self::UUID, new Money(Decimal::of('5'), new UnknownEnumValue(Currency::class, 'EUR')));
    }

    #[Test]
    public function aPriceChangeRefusesAPriceInACurrencyTheSdkCannotSend(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown currency "EUR" cannot be sent.');

        new PriceChange(self::UUID, new Money(Decimal::of('5'), new UnknownEnumValue(Currency::class, 'EUR')));
    }
}
