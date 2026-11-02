<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Store;

use WhiteMarket\Sdk\Models\AchievementKind;
use WhiteMarket\Sdk\Models\AchievementName;
use WhiteMarket\Sdk\Models\AchievementStatus;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * An achievement of a store (`PersonStore.achievements`).
 */
final readonly class Achievement
{
    /**
     * @param list<AchievementParam> $params
     */
    public function __construct(
        public AchievementName|UnknownEnumValue $name,
        public int $points,
        public AchievementStatus|UnknownEnumValue $status,
        public AchievementKind|UnknownEnumValue $kind,
        public ?\DateTimeImmutable $unlockedAt,
        public array $params,
    ) {}
}
