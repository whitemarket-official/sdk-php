<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Live\ConnectRejected;
use WhiteMarket\Sdk\Live\ServerClosed;

#[CoversClass(ServerClosed::class)]
#[CoversClass(ConnectRejected::class)]
final class ServerClosedTest extends TestCase
{
    #[Test]
    public function theReasonIsMadePrintableAndTheMessageNamesTheCodeOnly(): void
    {
        $closed = new ServerClosed(3503, "a\x00b" . str_repeat('c', 200));

        self::assertSame('a?b' . str_repeat('c', 125), $closed->reason);
        self::assertSame(3503, $closed->closeCode);
        self::assertSame('The server closed the live connection with code 3503.', $closed->getMessage());
    }

    #[Test]
    public function connectRejectedNamesTheCodeOnly(): void
    {
        $rejected = new ConnectRejected(109, true);

        self::assertSame(109, $rejected->errorCode);
        self::assertTrue($rejected->temporary);
        self::assertSame('The server rejected the live connect command with error 109.', $rejected->getMessage());
    }
}
