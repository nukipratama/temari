<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use NoDiscard;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * How long the runner has been recovering, resolved once from their run
 * history so the briefing chip and the LLM signal stay in agreement instead
 * of showing two contradictory numbers.
 *
 * - {@see $hoursSinceLastRun} is literal hours since the most recent run —
 *   what the recency chip shows ("3 jam", "Lari hari ini").
 * - {@see $recoveryHours} is the same number, but null on a day the runner
 *   already ran: the briefing regenerates at ingest, moments after the run,
 *   so a literal count would read "0 jam" and citing it would fight the chip.
 *   On a run day the narration leans on {@see $ranToday} instead.
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

    #[NoDiscard]
    public static function forUser(User $user, Carbon $asOf): self
    {
        // Runs after $asOf must not count: a self-heal / dead-letter retry
        // recomputes a past-dated briefing, and leaking a later run into it
        // would misreport recovery (and weaken the readiness cap) for that day.
        $ceiling = $asOf->copy()->endOfDay();
        $lastStart = self::lastStartAtOrBefore($user, $ceiling);

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

        // The LLM recovery signal mirrors the chip on a rest day, but is null on
        // a run day (a ~0 count right after the run is meaningless and would
        // contradict the "ran today" framing).
        $recoveryHours = $ranToday ? null : $hoursSinceLastRun;

        return new self($hoursSinceLastRun, $ranToday, $daysSinceLastRun, $recoveryHours);
    }

    /**
     * The most recent activity start at or before $ceiling. $ceiling keeps the
     * window as-of the briefing date so a backdated recompute never sees a
     * later run.
     */
    private static function lastStartAtOrBefore(User $user, Carbon $ceiling): ?Carbon
    {
        $value = ActivityDetail::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->whereNotNull('start_date_local')
            ->where('start_date_local', '<=', $ceiling)
            ->orderByDesc('start_date_local')
            ->value('start_date_local');

        return $value === null ? null : Carbon::parse($value);
    }
}
