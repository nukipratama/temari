<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators\Concerns;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

/**
 * Steady-state continuity for the daily per-user narrators (daily greeting +
 * "Kata Temari" mascot voice). Reads the user's most recent earlier Done
 * narrative of the same kind so today's line continues yesterday's thread. The
 * daily subjects are keyed by a user id + a 'Y-m-d' discriminator, so "previous"
 * is the latest Done row whose discriminator is before the current day.
 */
trait ReadsPreviousDailyNarrative
{
    /**
     * The given kind's Done content for the user's most recent earlier day
     * (by 'Y-m-d' discriminator) under $subjectType. Null when no such
     * predecessor exists (first ever day, or it is not yet narrated), so the
     * narrator opens standalone.
     */
    private function previousDailyNarrative(string $subjectType, int $userId, AnalysisType $kind, Carbon $currentDay): ?string
    {
        return Analysis::query()
            ->where('subject_type', $subjectType)
            ->where('subject_id', $userId)
            ->where('analysis_type', $kind)
            ->where('status', AnalysisStatus::Done)
            ->where('discriminator', '<', $currentDay->toDateString())
            ->orderByDesc('discriminator')
            ->value('content');
    }
}
