<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * How long the runner has been recovering, resolved once from their run
 * history so the briefing chip and the LLM signal share one implementation
 * instead of two divergent copies.
 *
 * Two numbers, deliberately different:
 * - {@see $hoursSinceLastRun} is literal hours since the most recent run —
 *   what the recency chip shows ("3 jam", "Lari hari ini").
 * - {@see $recoveryHours} is the *recovery available for the next session*.
 *   When the last run is today it measures from the PRIOR distinct run day,
 *   not the run just finished, so a freshly-ingested run never reports "0 jam"
 *   and the LLM isn't told a same-day run means "no recovery".
 */
final readonly class RecoveryWindow
{
    public function __construct(
        public ?int $hoursSinceLastRun,
        public bool $ranToday,
        public ?int $daysSinceLastRun,
        public ?int $recoveryHours,
    ) {
    }

    public static function forUser(User $user, Carbon $asOf): self
    {
        // Runs after $asOf must not count: a self-heal / dead-letter retry
        // recomputes a past-dated briefing, and leaking a later run into it
        // would misreport recovery (and weaken the readiness cap) for that day.
        $ceiling = $asOf->copy()->endOfDay();
        $lastStart = self::lastStartBefore($user, $ceiling);

        if ($lastStart === null) {
            return new self(null, false, null, null);
        }

        // asOf is start-of-day; bump to now-of-day (or the day's end for a past
        // date) so a run earlier today reads as a few hours ago, not the whole
        // day. absolute:true guards against the signed-diff-to-0 bug.
        $now = $asOf->isSameDay(Carbon::now()) ? Carbon::now() : $asOf->copy()->endOfDay();
        $hoursSinceLastRun = max(0, (int) $lastStart->diffInHours($now, absolute: true));

        $ranToday = $lastStart->isSameDay($asOf);
        $daysSinceLastRun = (int) $lastStart->copy()->startOfDay()->diffInDays($asOf->copy()->startOfDay(), absolute: true);

        // Recovery for the *next* session. If they already ran today, measure
        // from the previous distinct run day so the signal reflects genuine
        // rest between real sessions, not the run they just logged. When today's
        // run is the only one on record there is no prior session to measure
        // from, so recovery is unknown (null) rather than a misleading ~0 that
        // would read as "no recovery" the moment a first-ever run is ingested.
        $recoveryHours = $hoursSinceLastRun;
        if ($ranToday) {
            $priorStart = self::lastStartBefore($user, $ceiling, $asOf->copy()->startOfDay());
            $recoveryHours = $priorStart !== null
                ? max(0, (int) $priorStart->diffInHours($now, absolute: true))
                : null;
        }

        return new self($hoursSinceLastRun, $ranToday, $daysSinceLastRun, $recoveryHours);
    }

    /**
     * The most recent activity start at or before $ceiling, optionally further
     * restricted to runs that started strictly before $strictlyBefore (used to
     * find the prior distinct day). $ceiling keeps the window as-of the briefing
     * date so a backdated recompute never sees a later run.
     */
    private static function lastStartBefore(User $user, Carbon $ceiling, ?Carbon $strictlyBefore = null): ?Carbon
    {
        $value = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('start_date_local')
            ->where('start_date_local', '<=', $ceiling)
            ->when($strictlyBefore !== null, fn ($q) => $q->where('start_date_local', '<', $strictlyBefore))
            ->orderByDesc('start_date_local')
            ->value('start_date_local');

        return $value === null ? null : Carbon::parse($value);
    }
}
