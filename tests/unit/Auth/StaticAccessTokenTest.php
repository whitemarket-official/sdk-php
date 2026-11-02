<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Auth\Secret;
use WhiteMarket\Sdk\Auth\StaticAccessToken;

#[CoversClass(StaticAccessToken::class)]
final class StaticAccessTokenTest extends TestCase
{
    private const TOKEN = 'synthetic-jwt-0c9e';

    #[Test]
    public function returnsTheSameTokenEvenWhenAskedToRefresh(): void
    {
        $provider = new StaticAccessToken(new Secret(self::TOKEN));

        self::assertSame(self::TOKEN, $provider->accessToken()->reveal());
        self::assertSame(self::TOKEN, $provider->refresh()->reveal());
    }

    #[Test]
    public function doesNotRevealTheTokenWhenDumped(): void
    {
        self::assertStringNotContainsString(self::TOKEN, print_r(new StaticAccessToken(new Secret(self::TOKEN)), true));
    }
}
