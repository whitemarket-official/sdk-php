<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Tests\Support;

use WhiteMarket\Sdk\Transport\Sleeper;

/**
 * Records the delays instead of waiting, so retry tests assert them.
 */
final class RecordingSleeper implements Sleeper
{
    /** @var list<float> */
    private array $delays = [];

    public function sleep(float $seconds): void
    {
        $this->delays[] = $seconds;
    }

    /**
     * @return list<float>
     */
    public function delays(): array
    {
        return $this->delays;
    }
}
