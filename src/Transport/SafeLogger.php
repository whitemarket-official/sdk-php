<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Wraps the user's PSR-3 logger so that a logging failure never changes the outcome of an API call.
 *
 * The transport logs after the response arrived. A logger that throws (a file handler that cannot open its file) would
 * otherwise escape a `market_buy` that already ran: no reconciliation, the customId lost (SDK_SPEC 1.4, 3.3).
 *
 * @internal built by {@see \WhiteMarket\Sdk\WhiteMarketClient} and {@see HttpTransport}
 */
final class SafeLogger extends AbstractLogger
{
    private function __construct(private readonly LoggerInterface $inner) {}

    /**
     * `$logger` itself when it is already wrapped.
     */
    public static function wrap(LoggerInterface $logger): LoggerInterface
    {
        return $logger instanceof self ? $logger : new self($logger);
    }

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        try {
            $this->inner->log($level, $message, $context);
        } catch (\Throwable) {
            // A broken logger must not turn a finished API call into an exception.
        }
    }
}
