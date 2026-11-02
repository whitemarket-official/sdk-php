<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

/**
 * Waits between retries, injectable so tests assert the delays instead of waiting.
 */
interface Sleeper
{
    public function sleep(float $seconds): void;
}
