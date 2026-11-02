<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use WhiteMarket\Sdk\Version;

/**
 * Checks and values {@see TransportOptions} and {@see \WhiteMarket\Sdk\Export\ExportOptions} share, so the two cannot
 * drift apart.
 *
 * @internal
 */
final class OptionRules
{
    private function __construct() {}

    /**
     * Tokens travel in headers: plain HTTP would expose them, so there is no way to turn TLS off (SDK_SPEC 8). User
     * information (`user:password@`) is refused: a credential in a URL ends up in logs, traces and redirect checks.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertHttpsOrigin(string $baseUrl): void
    {
        // `D` on every pattern: without it `$` also matches before a final line feed.
        if (preg_match('~^https://[^/?#\s]+$~iD', rtrim($baseUrl, '/')) !== 1) {
            throw new \InvalidArgumentException('baseUrl must be an https:// origin without a path, query or fragment.');
        }
        if (str_contains($baseUrl, '@')) {
            throw new \InvalidArgumentException('baseUrl must not contain credentials.');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertUserAgentSuffix(?string $userAgentSuffix): void
    {
        if ($userAgentSuffix !== null && preg_match('/^[\x20-\x7E]+$/D', $userAgentSuffix) !== 1) {
            throw new \InvalidArgumentException('userAgentSuffix must be non-empty printable ASCII.');
        }
    }

    /**
     * @throws \InvalidArgumentException
     */
    public static function assertPath(string $name, string $path): void
    {
        if (preg_match('~^/[^?#\s]*$~D', $path) !== 1) {
            throw new \InvalidArgumentException(\sprintf('%s must start with "/" and hold no query or fragment.', $name));
        }
    }

    /**
     * `whitemarket-sdk-php/<version> (PHP/<version>)`, followed by the suffix when one is set.
     */
    public static function userAgent(?string $userAgentSuffix): string
    {
        return Version::userAgent() . ($userAgentSuffix === null ? '' : ' ' . $userAgentSuffix);
    }
}
