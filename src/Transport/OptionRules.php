<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use Psr\Http\Message\RequestFactoryInterface;
use WhiteMarket\Sdk\Version;

/**
 * Checks and values {@see TransportOptions} and {@see \WhiteMarket\Sdk\Export\ExportOptions} share, so the two cannot
 * drift apart.
 *
 * @internal
 */
final class OptionRules
{
    /** Longest endpoint option in characters; the longest URL built from options stays far below any request-head cap. */
    public const MAX_ENDPOINT_LENGTH = 2048;

    private const PORT_MAX = 65535;

    private function __construct() {}

    /**
     * What every endpoint option must satisfy before any other rule: printable ASCII (an internationalised host in its
     * `xn--` form; the PHP HTTP stack disagrees on anything else) and a bounded length.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertEndpointText(string $option, string $value): void
    {
        if (preg_match('/^[\x20-\x7E]*$/D', $value) !== 1) {
            throw new \InvalidArgumentException(\sprintf('%s must be printable ASCII (an internationalised host in its xn-- form).', $option));
        }
        if (\strlen($value) > self::MAX_ENDPOINT_LENGTH) {
            throw new \InvalidArgumentException(\sprintf('%s must be at most %d characters.', $option, self::MAX_ENDPOINT_LENGTH));
        }
    }

    /**
     * Tokens travel in headers: plain HTTP would expose them, so there is no way to turn TLS off (SDK_SPEC 8). User
     * information (`user:password@`) is refused: a credential in a URL ends up in logs, traces and redirect checks.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertHttpsOrigin(string $baseUrl, string $option = 'baseUrl'): void
    {
        self::assertEndpointText($option, $baseUrl);
        // `D` on every pattern: without it `$` also matches before a final line feed.
        $origin = rtrim($baseUrl, '/');
        if (preg_match('~^https://[^/?#\s]+$~iD', $origin) !== 1) {
            throw new \InvalidArgumentException(\sprintf('%s must be an https:// origin without a path, query or fragment.', $option));
        }
        if (str_contains($baseUrl, '@')) {
            throw new \InvalidArgumentException(\sprintf('%s must not contain credentials.', $option));
        }
        if (!self::hasValidAuthority(substr($origin, \strlen('https://')))) {
            throw new \InvalidArgumentException(\sprintf('%s is not a valid URL.', $option));
        }
    }

    /**
     * Checked here because the layers of the PHP HTTP stack disagree: `host:44a3` is read as port 44 by one and refused
     * by another, and `[zz]` is refused only when the request is sent. A host that is merely not resolvable (an address
     * out of range, an `xn--` label that is not punycode) is accepted by every layer and fails at connect.
     */
    private static function hasValidAuthority(string $authority): bool
    {
        if (str_starts_with($authority, '[')) {
            if (preg_match('/^\[([^\]]*)\](?::(.*))?$/sD', $authority, $m) !== 1) {
                return false;
            }
            // An IPv6 literal packs into 16 bytes (an IPv4 one into 4); `inet_pton()` is core, no ext-filter needed.
            $packed = inet_pton($m[1]);
            if ($packed === false || \strlen($packed) !== 16) {
                return false;
            }
            $port = $m[2] ?? null;
        } else {
            $parts = explode(':', $authority, 2);
            // Observed 2026-11-15 (Guzzle curl handler through the proxy http://127.0.0.1:1, no real host): curl accepts
            // `_` and `~` in a host and refuses ! $ ' ( ) * + , ; = & " < > ^ ` { } with "Bad hostname"; `%` is refused
            // by Guzzle's host check at send. So a host is letters, digits, `.`, `-`, `_` and `~`.
            if (preg_match('/^[A-Za-z0-9._~-]+$/D', $parts[0]) !== 1) {
                return false;
            }
            $port = $parts[1] ?? null;
        }
        if ($port === null) {
            return true;
        }

        return preg_match('/^[0-9]{1,5}$/D', $port) === 1 && (int) $port >= 1 && (int) $port <= self::PORT_MAX;
    }

    /**
     * The request factory the transport uses must be able to build the URL an endpoint option leads to, so a URL the
     * PSR-7 implementation refuses fails at construction and not at the first request. The exception carries no
     * previous: the factory's message quotes the URL.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertBuildable(RequestFactoryInterface $requestFactory, string $option, string $url): void
    {
        if (!self::isBuildable($requestFactory, $url)) {
            throw new \InvalidArgumentException(\sprintf('%s is not a valid URL.', $option));
        }
    }

    private static function isBuildable(RequestFactoryInterface $requestFactory, string $url): bool
    {
        try {
            $requestFactory->createRequest('GET', $url);

            return true;
        } catch (\Throwable) {
            return false;
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
        self::assertEndpointText($name, $path);
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
