<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Transport;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Transport\ClientFailure;
use WhiteMarket\Sdk\Transport\Failure;

#[CoversClass(Failure::class)]
final class FailureTest extends TestCase
{
    #[Test]
    public function namesTheClassOfTheCauseOnly(): void
    {
        $e = Failure::named('Sending failed', new \DomainException('wss://host/path?token=SECRET-MARK'));

        self::assertSame('Sending failed (DomainException).', $e->getMessage());
        self::assertStringNotContainsString('MARK', $e->getMessage());
    }

    #[Test]
    public function isNeverChained(): void
    {
        $e = Failure::named('Sending failed', new \RuntimeException('cause'));

        self::assertNotInstanceOf(\Throwable::class, $e->getPrevious());
    }

    #[Test]
    public function unwrapsAClientFailureToTheClientsOwnClass(): void
    {
        $e = Failure::named('Request failed', new ClientFailure(new \UnexpectedValueException('SECRET-MARK'), true));

        self::assertSame('Request failed (UnexpectedValueException).', $e->getMessage());
    }
}
