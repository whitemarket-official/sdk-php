<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * Current time, injectable so token expiry and `Retry-After` dates are testable.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
