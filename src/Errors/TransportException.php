<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Errors;

/**
 * No HTTP response was received: DNS, TLS, connect, timeout or reading the body failed.
 */
final class TransportException extends WhiteMarketException {}
