<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Version;

final class VersionTest extends TestCase
{
    #[Test]
    public function userAgentNamesSdkVersionAndRuntime(): void
    {
        self::assertSame('whitemarket-sdk-php/0.1.0-dev (PHP/8.2.35)', Version::userAgent('8.2.35'));
    }

    #[Test]
    public function userAgentDefaultsToCurrentRuntime(): void
    {
        self::assertStringEndsWith('(PHP/' . PHP_VERSION . ')', Version::userAgent());
    }
}
