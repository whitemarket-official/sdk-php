<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Transport;

final class SystemRandomSource implements RandomSource
{
    private const RESOLUTION = 1 << 53;

    public function fraction(): float
    {
        return random_int(0, self::RESOLUTION - 1) / self::RESOLUTION;
    }
}
