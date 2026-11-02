<?php

declare(strict_types=1);

namespace WhiteMarket\Sdk\Models;

/**
 * Progress of a store achievement. A value the server adds later arrives as
 * {@see \WhiteMarket\Sdk\Models\UnknownEnumValue} (CLAUDE.md rule 5).
 */
enum AchievementStatus: string
{
    case Completed = 'COMPLETED';
    case InProgress = 'IN_PROGRESS';
    case Missed = 'MISSED';
    case NotStarted = 'NOT_STARTED';
}
