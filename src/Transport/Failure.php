<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use WhiteMarket\Sdk\Errors\TransportException;

/**
 * The exception for a failure of third-party code (a PSR-18 client, the WebSocket library) or of a decoder: it names the
 * class of the cause only and is never chained, because the cause may hold the request or the connect command with the
 * token in its message or its trace arguments.
 *
 * @internal
 */
final class Failure
{
    private function __construct() {}

    public static function named(string $what, #[\SensitiveParameter] \Throwable $cause): TransportException
    {
        $original = $cause instanceof ClientFailure ? $cause->failure : $cause;

        return new TransportException(\sprintf('%s (%s).', $what, $original::class));
    }
}
