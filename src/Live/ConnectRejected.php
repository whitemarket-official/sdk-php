<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Live;

use WhiteMarket\Sdk\Errors\WhiteMarketException;

/**
 * The server answered the connect command with an error reply (for example 109 token expired, 101 unauthorized).
 * `$errorCode` is the Centrifugo error code, `$temporary` its `temporary` flag. The message names the code only, never
 * the server's text. Not a {@see \WhiteMarket\Sdk\Errors\TransportException} for the same reason as {@see ServerClosed}.
 *
 * @internal
 */
final class ConnectRejected extends WhiteMarketException
{
    public function __construct(public readonly int $errorCode, public readonly bool $temporary = false)
    {
        parent::__construct(\sprintf('The server rejected the live connect command with error %d.', $errorCode));
    }
}
