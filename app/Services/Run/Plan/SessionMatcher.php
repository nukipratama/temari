<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\DistanceFormatter;
use Illuminate\Support\Carbon;

/**
 * Matches what the athlete actually ran against what the plan asked for,
 * one day at a time — a continuous km-ratio score, not just a bucket, so a
 * 3 km jog against a 20 km long run reads as a real number, not just
 * {@see PlannedSessionStatus::Partial}. `plan:score-compliance` (daily) is
 * what actually calls {@see self::scoreFor()} and persists the result onto
 * each {@see \App\Models\PlannedSession} row — this class stays render-safe
 * (`statuses()`) only as a fallback for a past row the daily command hasn't
 * reached yet, so a page load never shows a stale `planned` for a day that's
 * already over.
 *
 * Loads a whole date range in one query — the per-day existence check this
 * replaced on the Plan tab was an N+1 across every rendered week.
 */
final class SessionMatcher
{
    /** Fraction of the prescribed km that counts the session as run as asked. */
    public const float DONE_FRACTION = 0.85;

    /** Below this fraction the session counts as missed, not partially done. */
    public const float PARTIAL_FRACTION = 0.35;

    /** At or above this fraction the athlete ran significantly more than prescribed. */
    public const float OVERREACHED_FRACTION = 1.30;

    /**
     * Render-time fallback for whatever subset of `$plannedKmByDate` is
     * still `planned` despite being past-dated — see the class docblock.
     * Callers should only pass the stale subset, not the whole range, so a
     * healthy day never pays for a query it doesn't need.
     *
     * @param  array<string, float>  $plannedKmByDate  Y-m-d => prescribed km (0.0 on a rest day)
     * @param  array<string, bool>  $skippedByDate  Y-m-d => whether the athlete excused this day
     * @return array<string, PlannedSessionStatus>  Y-m-d => status
     */
    public function statuses(User $user, array $plannedKmByDate, array $skippedByDate, Carbon $today): array
    {
        return array_map(
            static fn (array $result): PlannedSessionStatus => $result['status'],
            $this->scoreRange($user, $plannedKmByDate, $skippedByDate, $today),
        );
    }

    /**
     * `plan:score-compliance`'s entry point — the same per-day judgment as
     * {@see self::statuses()}, but returning the full verdict (score,
     * `ran_anyway`) each row needs written back, not just the status label.
     *
     * @param  array<string, float>  $plannedKmByDate  Y-m-d => prescribed km (0.0 on a rest day)
     * @param  array<string, bool>  $skippedByDate  Y-m-d => whether the athlete excused this day
     * @return array<string, array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool}>
     */
    public function scoreRange(User $user, array $plannedKmByDate, array $skippedByDate, Carbon $today): array
    {
        if ($plannedKmByDate === []) {
            return [];
        }

        $completedKm = $this->completedKmByDate($user, $plannedKmByDate);
        $results = [];
        foreach ($plannedKmByDate as $date => $plannedKm) {
            $isPast = Carbon::parse($date)->lt($today);
            $results[$date] = self::scoreFor($plannedKm, $completedKm[$date] ?? 0.0, $isPast, $skippedByDate[$date] ?? false);
        }

        return $results;
    }

    /**
     * The single source of truth for turning a day's (prescribed km,
     * completed km) into a persisted verdict. `$skipped` always wins — an
     * excused day is never scored, regardless of what happened to be logged
     * that date. A rest day (`$plannedKm <= 0`) is always `Done`; whether
     * something was logged anyway is reported separately via `ran_anyway`
     * rather than changing the status itself.
     *
     * @return array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool}
     */
    public static function scoreFor(float $plannedKm, float $completedKm, bool $isPast, bool $skipped): array
    {
        if (! $isPast) {
            return ['status' => PlannedSessionStatus::Planned, 'score' => null, 'ran_anyway' => false];
        }
        if ($skipped) {
            return ['status' => PlannedSessionStatus::Skip, 'score' => null, 'ran_anyway' => false];
        }
        if ($plannedKm <= 0.0) {
            return ['status' => PlannedSessionStatus::Done, 'score' => null, 'ran_anyway' => $completedKm > 0.0];
        }

        $ratio = $completedKm / $plannedKm;
        $score = (int) round($ratio * 100);

        $status = match (true) {
            $ratio >= self::OVERREACHED_FRACTION => PlannedSessionStatus::Overreached,
            $ratio >= self::DONE_FRACTION => PlannedSessionStatus::Done,
            $ratio >= self::PARTIAL_FRACTION => PlannedSessionStatus::Partial,
            default => PlannedSessionStatus::Missed,
        };

        return ['status' => $status, 'score' => $score, 'ran_anyway' => false];
    }

    /**
     * @param  non-empty-array<string, float>  $plannedKmByDate
     * @return array<string, float>  Y-m-d => km run that day
     */
    private function completedKmByDate(User $user, array $plannedKmByDate): array
    {
        $dates = array_keys($plannedKmByDate);

        /** @var array<string, mixed> $rows */
        $rows = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [
                Carbon::parse(min($dates))->startOfDay(),
                Carbon::parse(max($dates))->endOfDay(),
            ])
            ->selectRaw('DATE(activity_details.start_date_local) as d, SUM(activity_details.distance) as meters')
            ->groupBy('d')
            ->pluck('meters', 'd')
            ->all();

        return array_map(static fn (mixed $meters): float => DistanceFormatter::km((float) $meters), $rows);
    }
}
