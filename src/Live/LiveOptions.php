<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Transport\OptionRules;

/**
 * Settings of a live session (SDK_SPEC 4). The API origin comes from the transport options, not from here.
 * Timeouts are in seconds and apply to the default clients only.
 *
 * `readSlice` is how long one blocking read waits before the connection checks its deadline. It is a whole number of
 * seconds: Guzzle's stream handler turns a fractional `read_timeout` into microseconds wrongly (`* 100000`), so a
 * fractional slice would be cut tenfold.
 *
 * `maxMessageBytes` caps one Centrifugo message (a WebSocket frame or message, an SSE event). `maxReconnectAttempts`
 * null means unlimited.
 */
final readonly class LiveOptions
{
    public const DEFAULT_WS_PATH = '/ws_endpoint';

    public const DEFAULT_SSE_PATH = '/sse_endpoint';

    public const MIN_MESSAGE_BYTES = 1024;

    public const MAX_MESSAGE_BYTES = 64 * 1024 * 1024;

    public function __construct(
        public LiveTransport $transport = LiveTransport::WebSocket,
        public string $wsPath = self::DEFAULT_WS_PATH,
        public string $ssePath = self::DEFAULT_SSE_PATH,
        public float $connectTimeout = 10.0,
        public int $readSlice = 1,
        public int $maxMessageBytes = 1_048_576,
        public ?int $maxReconnectAttempts = null,
        public ?string $userAgentSuffix = null,
    ) {
        OptionRules::assertPath('wsPath', $wsPath);
        OptionRules::assertPath('ssePath', $ssePath);
        if ($connectTimeout <= 0) {
            throw new \InvalidArgumentException('connectTimeout must be positive.');
        }
        if ($readSlice < 1) {
            throw new \InvalidArgumentException('readSlice must be a whole number of seconds, at least 1.');
        }
        if ($maxMessageBytes < self::MIN_MESSAGE_BYTES || $maxMessageBytes > self::MAX_MESSAGE_BYTES) {
            throw new \InvalidArgumentException(\sprintf('maxMessageBytes must be between %d and %d.', self::MIN_MESSAGE_BYTES, self::MAX_MESSAGE_BYTES));
        }
        if ($maxReconnectAttempts !== null && $maxReconnectAttempts < 1) {
            throw new \InvalidArgumentException('maxReconnectAttempts must be at least 1 or null for unlimited.');
        }
        OptionRules::assertUserAgentSuffix($userAgentSuffix);
    }

    /**
     * `whitemarket-sdk-php/<version> (PHP/<version>)`, followed by the suffix when one is set.
     */
    public function userAgent(): string
    {
        return OptionRules::userAgent($this->userAgentSuffix);
    }
}
