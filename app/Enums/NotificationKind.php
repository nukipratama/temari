<?php

declare(strict_types=1);

namespace App\Enums;

use App\Services\AI\AnalysisType;

/**
 * What an inbox row is, so the inbox can render (and replay) each row with the
 * right treatment rather than as a uniform line of text.
 */
enum NotificationKind: string
{
    case PostRun = 'post_run';
    case WeeklyRecap = 'weekly_recap';
    case MonthlyRecap = 'monthly_recap';
    case StreakReminder = 'streak_reminder';
    case Unlock = 'unlock';
    case Test = 'test';

    /** Null for an analysis type that never notifies. */
    public static function forAnalysisType(AnalysisType $type): ?self
    {
        return match ($type) {
            AnalysisType::PostRunSpeech => self::PostRun,
            AnalysisType::WeeklyRecap => self::WeeklyRecap,
            AnalysisType::MonthlyRecap => self::MonthlyRecap,
            default => null,
        };
    }
}
