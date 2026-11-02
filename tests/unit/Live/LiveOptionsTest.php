<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Unit\Live;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WhiteMarket\Sdk\Live\LiveOptions;
use WhiteMarket\Sdk\Live\LiveTransport;
use WhiteMarket\Sdk\Transport\OptionRules;
use WhiteMarket\Sdk\Version;

#[CoversClass(LiveOptions::class)]
#[CoversClass(OptionRules::class)]
final class LiveOptionsTest extends TestCase
{
    #[Test]
    public function hasTheSpecDefaults(): void
    {
        $options = new LiveOptions();

        self::assertSame([LiveTransport::WebSocket, '/ws_endpoint', '/sse_endpoint', 10.0, 1, 1_048_576, null, null], [
            $options->transport,
            $options->wsPath,
            $options->ssePath,
            $options->connectTimeout,
            $options->readSlice,
            $options->maxMessageBytes,
            $options->maxReconnectAttempts,
            $options->userAgentSuffix,
        ]);
    }

    #[Test]
    public function buildsTheUserAgent(): void
    {
        self::assertSame(Version::userAgent(), (new LiveOptions())->userAgent());
        self::assertSame(Version::userAgent() . ' bot/1.0', (new LiveOptions(userAgentSuffix: 'bot/1.0'))->userAgent());
    }

    /**
     * @return iterable<string, array{array{wsPath?: string, ssePath?: string, connectTimeout?: float, readSlice?: int, maxMessageBytes?: int, maxReconnectAttempts?: int, userAgentSuffix?: string}}>
     */
    public static function invalidOptions(): iterable
    {
        yield 'ws path without slash' => [['wsPath' => 'ws']];
        yield 'ws path with query' => [['wsPath' => '/ws?x=1']];
        yield 'sse path with fragment' => [['ssePath' => '/sse#a']];
        yield 'sse path with space' => [['ssePath' => '/s se']];
        yield 'sse path final line feed' => [['ssePath' => "/sse\n"]];
        yield 'zero connect timeout' => [['connectTimeout' => 0.0]];
        yield 'negative connect timeout' => [['connectTimeout' => -1.0]];
        yield 'zero read slice' => [['readSlice' => 0]];
        yield 'negative read slice' => [['readSlice' => -1]];
        yield 'max message below the minimum' => [['maxMessageBytes' => LiveOptions::MIN_MESSAGE_BYTES - 1]];
        yield 'max message above the maximum' => [['maxMessageBytes' => LiveOptions::MAX_MESSAGE_BYTES + 1]];
        yield 'zero reconnect attempts' => [['maxReconnectAttempts' => 0]];
        yield 'negative reconnect attempts' => [['maxReconnectAttempts' => -1]];
        yield 'empty suffix' => [['userAgentSuffix' => '']];
        yield 'control bytes in suffix' => [['userAgentSuffix' => "a\r\nb"]];
    }

    /**
     * @param array{wsPath?: string, ssePath?: string, connectTimeout?: float, readSlice?: int, maxMessageBytes?: int, maxReconnectAttempts?: int, userAgentSuffix?: string} $arguments
     */
    #[Test]
    #[DataProvider('invalidOptions')]
    public function rejectsInvalidOptions(array $arguments): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LiveOptions(
            wsPath: $arguments['wsPath'] ?? '/ws',
            ssePath: $arguments['ssePath'] ?? '/sse',
            connectTimeout: $arguments['connectTimeout'] ?? 10.0,
            readSlice: $arguments['readSlice'] ?? 1,
            maxMessageBytes: $arguments['maxMessageBytes'] ?? 1024,
            maxReconnectAttempts: $arguments['maxReconnectAttempts'] ?? null,
            userAgentSuffix: $arguments['userAgentSuffix'] ?? null,
        );
    }

    #[Test]
    public function namesTheOptionInTheMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ssePath');

        new LiveOptions(ssePath: 'sse');
    }

    #[Test]
    public function acceptsTheBounds(): void
    {
        $low = new LiveOptions(LiveTransport::Sse, '/', '/', 0.001, 1, LiveOptions::MIN_MESSAGE_BYTES, 1);
        $high = new LiveOptions(readSlice: 60, maxMessageBytes: LiveOptions::MAX_MESSAGE_BYTES);

        self::assertSame([LiveOptions::MIN_MESSAGE_BYTES, 1, LiveTransport::Sse], [$low->maxMessageBytes, $low->maxReconnectAttempts, $low->transport]);
        self::assertSame([60, LiveOptions::MAX_MESSAGE_BYTES], [$high->readSlice, $high->maxMessageBytes]);
    }
}
