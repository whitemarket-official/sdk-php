<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * How a store achievement is earned. A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum AchievementKind: string
{
    case Action = 'ACTION';
    case Checklist = 'CHECKLIST';
    case Limit = 'LIMIT';
    case Progress = 'PROGRESS';
}
