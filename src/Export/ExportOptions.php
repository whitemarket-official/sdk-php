<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Export;

use WhiteMarket\Sdk\Transport\OptionRules;

/**
 * Settings of the export client (SDK_SPEC 1.3, 3.10, 8). Timeouts are in seconds and apply to the default Guzzle
 * client only, because PSR-18 has no timeout API. There is no total timeout: an export is large and a slow download is
 * fine as long as bytes keep arriving; `idleTimeout` is the longest silence on the connection.
 *
 * `maxBodyBytes` bounds the DECOMPRESSED size of the calls that read a whole file into memory (`meta()`, `prices()`,
 * `streamPrices()`), so a compression bomb cannot exhaust memory. `download()` is not bounded: it writes to its
 * destination.
 *
 * `jsonStreamDecoder` picks the parser of `streamProducts()` for JSON ({@see JsonStreamDecoder}); naming one whose
 * extension is not loaded is an argument error.
 */
final readonly class ExportOptions
{
    public const DEFAULT_BASE_URL = 'https://export.white.market';

    public function __construct(
        public string $baseUrl = self::DEFAULT_BASE_URL,
        public float $connectTimeout = 10.0,
        public float $idleTimeout = 60.0,
        public int $maxBodyBytes = 64 * 1024 * 1024,
        public ?string $userAgentSuffix = null,
        public JsonStreamDecoder $jsonStreamDecoder = JsonStreamDecoder::Auto,
    ) {
        if (!$jsonStreamDecoder->isAvailable()) {
            throw new \InvalidArgumentException(\sprintf('The JSON stream decoder "%s" needs its PHP extension, which is not loaded.', $jsonStreamDecoder->value));
        }
        OptionRules::assertHttpsOrigin($baseUrl);
        if ($connectTimeout <= 0 || $idleTimeout <= 0) {
            throw new \InvalidArgumentException('Timeouts must be positive.');
        }
        if ($maxBodyBytes < 1) {
            throw new \InvalidArgumentException('maxBodyBytes must be at least 1.');
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
