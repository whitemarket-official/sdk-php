<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Errors;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Errors\DecodeException;

#[CoversClass(DecodeException::class)]
final class DecodeExceptionTest extends TestCase
{
    #[Test]
    public function theMessageIsNeutralAboutTheSourceOfTheResponse(): void
    {
        $e = new DecodeException('prices[3].price', 'not a decimal number');

        self::assertSame('Cannot decode the response at "prices[3].price": not a decimal number', $e->getMessage());
        self::assertSame('prices[3].price', $e->path);
    }
}
