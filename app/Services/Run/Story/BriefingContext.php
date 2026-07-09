<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\Readiness;
use Illuminate\Support\Carbon;

/**
 * Extra signals the Briefing LLM should know about so its output sounds
 * personal instead of generic. Built once per Briefing call and injected
 * as fields into the user message sent to {@see \App\Services\AI\Narrators\BriefingNarrator}.
 *
 * `timeBucket` lets the LLM frame its suggestion by the actual hour the
 * user opened the dashboard (morning prompt vs evening prompt feel
 * different). `consecutiveWeeksActive` substitutes for the consecutive-day
 * streak we don't track; reuses WeeklySnapshot rows we already maintain.
 *
 * `readinessCeiling` is the deterministic safety cap ({@see Readiness}): the
 * LLM may suggest at or below it, never above. `fitnessTrend` and
 * `buildNudge` give it trajectory (is the runner ramping, or coasting into
 * detraining) so it stops defaulting to easy/recovery for lack of context.
 */
final readonly class BriefingContext
{
    public function __construct(
        public ?int $thisWeekRuns,
        public ?int $lastWeekRuns,
        public ?float $thisWeekKm,
        public ?float $lastWeekKm,
        public ?int $recoveryHours,
        public bool $ranToday,
        public ?int $daysSinceLastRun,
        public ?string $formStatus,
        /** `subuh` (4-5) · `pagi` (6-10) · `siang` (11-14) · `sore` (15-18) · `malam` (19-3) */
        public string $timeBucket,
        /** Weeks in a row with at least 1 run, ending at the current week. */
        public int $consecutiveWeeksActive,
        /** CTL-slope trajectory over recent weeks: `naik` / `plateau` / `turun`. */
        public string $fitnessTrend,
        /** Week-over-week volume change (%), null when there is no prior-week baseline. */
        public ?float $volumeRampPct,
        /** Hardest session intensity to encourage today: rest / easy_only / moderate_ok / quality_ok. */
        public string $readinessCeiling,
        /** Gentle "build, don't coast" flag for the fresh-but-detraining case. */
        public bool $buildNudge,
    ) {
    }

    /**
     * @param  array<string, mixed>|null  $load  Live TrainingLoad summary (form_status/monotony);
     *                                            when null, readiness falls back to the weekly snapshot.
     */
    public static function forUser(User $user, Carbon $asOf, ?array $load = null): self
    {
        $thisWeekEnd = $asOf->copy()->endOfWeek(Carbon::SUNDAY);
        $lastWeekEnd = $thisWeekEnd->copy()->subWeek();

        // Bound to weeks at or before the briefing week so a backdated recompute
        // (self-heal / dead-letter retry) reads fitness_trend from the state as
        // of $asOf, not from weeks that came after it.
        /** @var array<string, WeeklySnapshot> $byDate */
        $byDate = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', '<=', $thisWeekEnd->toDateString())
            ->orderByDesc('week_ending')
            ->limit(12)
            ->get()
            ->keyBy(fn (WeeklySnapshot $row): string => $row->week_ending->toDateString())
            ->all();

        $thisWeek = $byDate[$thisWeekEnd->toDateString()] ?? null;
        $lastWeek = $byDate[$lastWeekEnd->toDateString()] ?? null;

        $snapshotFormStatus = null;
        if ($thisWeek !== null && $thisWeek->form_status !== null) {
            $snapshotFormStatus = $thisWeek->form_status;
        } elseif ($lastWeek !== null) {
            $snapshotFormStatus = $lastWeek->form_status;
        }

        $recovery = RecoveryWindow::forUser($user, $asOf);
        $volumeRampPct = self::volumeRampPct($thisWeek?->distance_km, $lastWeek?->distance_km);
        $fitnessTrend = self::fitnessTrend($byDate);

        // Readiness keys off the live load when we have it (same numbers the LLM
        // sees), falling back to the weekly snapshot otherwise.
        $snapshotMonotony = null;
        if ($thisWeek !== null && $thisWeek->monotony !== null) {
            $snapshotMonotony = $thisWeek->monotony;
        } elseif ($lastWeek !== null) {
            $snapshotMonotony = $lastWeek->monotony;
        }
        // The form_status shown to the LLM and the one readiness caps off must
        // be the same source, or the prompt sees a snapshot form that
        // contradicts the ceiling. Prefer the live load, fall back to snapshot.
        $formStatus = self::stringOrNull($load['form_status'] ?? null) ?? $snapshotFormStatus;
        $readinessMonotony = self::floatOrNull($load['monotony'] ?? null) ?? $snapshotMonotony;

        $readiness = Readiness::assess(
            formStatus: $formStatus,
            recoveryHours: $recovery->recoveryHours,
            ranToday: $recovery->ranToday,
            monotony: $readinessMonotony,
            volumeRampPct: $volumeRampPct,
            fitnessTrend: $fitnessTrend,
        );

        return new self(
            thisWeekRuns: $thisWeek?->runs,
            lastWeekRuns: $lastWeek?->runs,
            thisWeekKm: $thisWeek?->distance_km,
            lastWeekKm: $lastWeek?->distance_km,
            recoveryHours: $recovery->recoveryHours,
            ranToday: $recovery->ranToday,
            daysSinceLastRun: $recovery->daysSinceLastRun,
            formStatus: $formStatus,
            timeBucket: self::bucketFor($asOf),
            consecutiveWeeksActive: self::countConsecutiveActiveWeeks($byDate, $thisWeekEnd),
            fitnessTrend: $fitnessTrend,
            volumeRampPct: $volumeRampPct,
            readinessCeiling: $readiness->ceiling->value,
            buildNudge: $readiness->buildNudge,
        );
    }

    /**
     * Week-over-week volume change as a percentage, rounded. Null when there is
     * no prior-week baseline (or it was a zero-distance week) to compare against.
     */
    private static function volumeRampPct(?float $thisWeekKm, ?float $lastWeekKm): ?float
    {
        if ($thisWeekKm === null || $lastWeekKm === null || $lastWeekKm <= 0.0) {
            return null;
        }

        return round((($thisWeekKm - $lastWeekKm) / $lastWeekKm) * 100, 1);
    }

    /**
     * Direction of the CTL (fitness) slope over the most recent weeks, from the
     * snapshot rows already loaded. `naik` when the latest reading is clearly
     * above the oldest in the window, `turun` when clearly below, else
     * `plateau` (including too-few data points to judge a trend).
     *
     * @param  array<string, WeeklySnapshot>  $byDate
     */
    private static function fitnessTrend(array $byDate): string
    {
        /** @var list<float> $series chronological ctl_42d, oldest first */
        $series = collect($byDate)
            ->sortKeys()
            ->map(fn (WeeklySnapshot $row): ?float => $row->ctl_42d)
            ->filter(fn (?float $ctl): bool => $ctl !== null)
            ->values()
            ->all();

        $series = array_slice($series, -4);
        if (count($series) < 2) {
            return 'plateau';
        }

        $first = $series[0];
        $last = $series[count($series) - 1];
        if ($first <= 0.0) {
            return $last > 0.0 ? 'naik' : 'plateau';
        }

        $changePct = (($last - $first) / $first) * 100;

        return match (true) {
            $changePct > 5.0 => 'naik',
            $changePct < -5.0 => 'turun',
            default => 'plateau',
        };
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    private static function floatOrNull(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? (float) $value : null;
    }

    /**
     * @param  array<string, WeeklySnapshot>  $byDate  Keyed by week_ending ISO date.
     */
    private static function countConsecutiveActiveWeeks(array $byDate, Carbon $thisWeekEnd): int
    {
        $count = 0;
        $cursor = $thisWeekEnd->copy();
        while (true) {
            $row = $byDate[$cursor->toDateString()] ?? null;
            if ($row === null || ($row->runs ?? 0) <= 0) {
                break;
            }
            $count++;
            $cursor->subWeek();
        }

        return $count;
    }

    private static function bucketFor(Carbon $asOf): string
    {
        $hour = (int) $asOf->format('H');

        return match (true) {
            $hour >= 4 && $hour <= 5 => 'subuh',
            $hour >= 6 && $hour <= 10 => 'pagi',
            $hour >= 11 && $hour <= 14 => 'siang',
            $hour >= 15 && $hour <= 18 => 'sore',
            default => 'malam',
        };
    }

    /**
     * Sketch of the deltas, ready to JSON-encode straight into the user
     * message context. Keys are short so they don't bloat token usage.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'this_week_runs' => $this->thisWeekRuns,
            'last_week_runs' => $this->lastWeekRuns,
            'this_week_km' => $this->thisWeekKm,
            'last_week_km' => $this->lastWeekKm,
            'recovery_hours' => $this->recoveryHours,
            'ran_today' => $this->ranToday,
            'days_since_last_run' => $this->daysSinceLastRun,
            'form_status' => $this->formStatus,
            'time_bucket' => $this->timeBucket,
            'consecutive_weeks_active' => $this->consecutiveWeeksActive,
            'fitness_trend' => $this->fitnessTrend,
            'volume_ramp_pct' => $this->volumeRampPct,
            'readiness_ceiling' => $this->readinessCeiling,
            'build_nudge' => $this->buildNudge,
        ];
    }
}
