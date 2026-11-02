<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk;

/**
 * SDK version, sent in the User-Agent header (SDK_SPEC 1.3).
 */
final class Version
{
    public const SDK = '0.1.0-dev';

    /**
     * `whitemarket-sdk-php/<version> (PHP/<runtime version>)`.
     */
    public static function userAgent(string $runtimeVersion = PHP_VERSION): string
    {
        return \sprintf('whitemarket-sdk-php/%s (PHP/%s)', self::SDK, $runtimeVersion);
    }
}
