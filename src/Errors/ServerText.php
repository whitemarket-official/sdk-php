<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * Server-controlled text kept in exceptions, events and logs: printable ASCII only (anything else becomes `?`), at most
 * {@see self::MAX_BYTES} bytes.
 *
 * @internal
 */
final class ServerText
{
    public const MAX_BYTES = 128;

    private function __construct() {}

    public static function printable(#[\SensitiveParameter] string $text): string
    {
        return substr((string) preg_replace('/[^\x20-\x7E]/', '?', $text), 0, self::MAX_BYTES);
    }
}
