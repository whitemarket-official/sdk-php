<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Selling;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Models\DeliveryPromise;
use WhiteMarket\Sdk\Models\DeliveryType;
use WhiteMarket\Sdk\Selling\ListingOptions;

#[CoversClass(ListingOptions::class)]
final class ListingOptionsTest extends TestCase
{
    #[Test]
    public function defaultsToSemiDeliveryAndTheServerDefaultsForTheRest(): void
    {
        $options = new ListingOptions();

        self::assertSame([DeliveryType::Semi, null, null], [$options->deliveryType, $options->deliveryPromise, $options->lifetime]);
    }

    #[Test]
    public function aManualDeliveryAPromiseAndALifetimeAreKept(): void
    {
        $options = new ListingOptions(DeliveryType::Manual, DeliveryPromise::Hour1, 3600);

        self::assertSame([DeliveryType::Manual, DeliveryPromise::Hour1, 3600], [$options->deliveryType, $options->deliveryPromise, $options->lifetime]);
    }

    #[Test]
    public function rejectsAutoDeliveryWithAClearMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AUTO delivery is reserved by the server and rejected; use SEMI or MANUAL.');

        new ListingOptions(DeliveryType::Auto);
    }

    /**
     * SDK_SPEC 3.5: 60 .. 31536000 seconds.
     *
     * @return iterable<string, array{int, bool}> lifetime, accepted
     */
    public static function lifetimes(): iterable
    {
        yield 'one below the minimum' => [59, false];
        yield 'the minimum' => [60, true];
        yield 'the maximum' => [31536000, true];
        yield 'one above the maximum' => [31536001, false];
        yield 'zero' => [0, false];
        yield 'negative' => [-60, false];
    }

    #[Test]
    #[DataProvider('lifetimes')]
    public function validatesTheLifetimeBounds(int $lifetime, bool $accepted): void
    {
        if (!$accepted) {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage(\sprintf('The lifetime must be 60..31536000 seconds, got %d.', $lifetime));
        }

        $options = new ListingOptions(lifetime: $lifetime);

        self::assertSame($lifetime, $options->lifetime);
    }
}
