<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * What a PSR-18 client threw while sending, however it was typed: the marker {@see RetryLoop::sendVia()} raises so the
 * loop can tell a failure of the client from an exception the SDK threw itself. The original is kept for its class and
 * for the network-error decision only; it is never chained, because it holds the request and its credentials.
 *
 * @internal
 */
final class ClientFailure extends \RuntimeException
{
    public function __construct(
        #[\SensitiveParameter]
        public readonly \Throwable $failure,
        public readonly bool $network,
    ) {
        parent::__construct('The HTTP client failed.');
    }
}
