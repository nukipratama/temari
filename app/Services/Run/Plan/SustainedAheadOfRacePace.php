<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\AdaptationReason;
use App\Models\PlanAdaptation;
use Illuminate\Support\Carbon;

/**
 * Whether the athlete has held {@see AdaptationReason::AheadOfRacePace} for
 * {@see self::SUSTAINED_WEEKS} consecutive evaluated weeks running up to and
 * including the current one — the signal
 * {@see \App\Services\AI\Narrators\PlanSeasonVoiceNarrator} reads before
 * telling the athlete their goal may be conservative. A single fast week is
 * noise; {@see PlanAdapter::RACE_GAP_MARGIN} already defines "clearly ahead",
 * so this only asks whether it held.
 *
 * Reads {@see PlanAdaptation}, written once per regeneration
 * (`unique(user_id, week_start)`), rather than recomputing the race
 * projection itself — the adapter's own verdict is the fact to trust. A
 * missing week (one that skipped regeneration, or hasn't happened yet)
 * reads as not sustained rather than guessing across the gap.
 */
final readonly class SustainedAheadOfRacePace
{
    /** Two consecutive evaluated weeks: a single fast week is noise, and a third week adds nothing this doesn't already say. */
    public const int SUSTAINED_WEEKS = 2;

    public function forUser(int $userId, Carbon $currentWeekStart): bool
    {
        $weekStarts = [];
        for ($i = 0; $i < self::SUSTAINED_WEEKS; $i++) {
            $weekStarts[] = $currentWeekStart->copy()->subWeeks($i)->toDateString();
        }

        $reasons = PlanAdaptation::query()
            ->where('user_id', $userId)
            ->whereIn('week_start', $weekStarts)
            ->pluck('reason', 'week_start');

        return array_all($weekStarts, fn (string $weekStart): bool => ($reasons[$weekStart] ?? null) === AdaptationReason::AheadOfRacePace);
    }
}
