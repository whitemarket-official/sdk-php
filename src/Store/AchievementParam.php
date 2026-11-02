<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Store;

use WhiteMarket\Sdk\Models\AchievementCondition;
use WhiteMarket\Sdk\Models\UnknownEnumValue;

/**
 * One parameter of an achievement (`AchievementBooleanParam` or `AchievementStringParam`, proposal P17).
 */
final readonly class AchievementParam
{
    /**
     * @param bool|string $value a boolean for a boolean parameter, a string for a string parameter
     */
    public function __construct(
        public AchievementCondition|UnknownEnumValue $name,
        public bool|string $value,
    ) {}
}
