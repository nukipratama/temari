<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
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
 * (`statuses()`) for a past row the daily command hasn't reached yet, so a
 * page load never shows a stale `planned` for a day that's already over, and
 * for today, which the daily command deliberately never reaches.
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
     * still `planned` despite being past-dated, plus today — see the class
     * docblock.
     * Callers should only pass the stale subset, not the whole range, so a
     * healthy day never pays for a query it doesn't need.
     *
     * @param  array<string, float>  $plannedKmByDate  Y-m-d => prescribed km (0.0 on a rest day)
     * @param  array<string, bool>  $excusedByDate  Y-m-d => whether this day is excused — the athlete skipped it,
     *                                               or the readiness clamp downgraded it to a full rest
     * @return array<string, PlannedSessionStatus>  Y-m-d => status
     */
    public function statuses(User $user, array $plannedKmByDate, array $excusedByDate, Carbon $today): array
    {
        return array_map(
            static fn (array $result): PlannedSessionStatus => $result['status'],
            $this->scoreRange($user, $plannedKmByDate, $excusedByDate, $today),
        );
    }

    /**
     * `plan:score-compliance`'s entry point — the same per-day judgment as
     * {@see self::statuses()}, but returning the full verdict (score,
     * `ran_anyway`) each row needs written back, not just the status label.
     *
     * @param  array<string, float>  $plannedKmByDate  Y-m-d => prescribed km (0.0 on a rest day)
     * @param  array<string, bool>  $excusedByDate  Y-m-d => whether this day is excused — the athlete skipped it,
     *                                               or the readiness clamp downgraded it to a full rest
     * @return array<string, array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool}>
     */
    public function scoreRange(User $user, array $plannedKmByDate, array $excusedByDate, Carbon $today): array
    {
        if ($plannedKmByDate === []) {
            return [];
        }

        $completed = $this->completedKmByDate($user, $plannedKmByDate);
        $longRunDates = $this->longRunDates($user, array_keys($plannedKmByDate));
        $results = [];
        foreach ($plannedKmByDate as $date => $plannedKm) {
            $isPast = Carbon::parse($date)->lt($today);
            $day = $completed[$date] ?? ['sum' => 0.0, 'longest' => 0.0];
            // A long run's training effect is continuity, so its day is
            // credited from its single longest run. Everywhere else the day's
            // runs add up, because easy volume genuinely does.
            $completedKm = ($longRunDates[$date] ?? false) ? $day['longest'] : $day['sum'];
            $results[$date] = self::scoreFor($plannedKm, $completedKm, $isPast, $excusedByDate[$date] ?? false);
        }

        return $results;
    }

    /**
     * The single source of truth for turning a day's (prescribed km,
     * completed km) into a verdict. `$excused` always wins — an excused day
     * is never scored, regardless of what happened to be logged that date; it
     * covers both an athlete's own skip and a readiness clamp that downgraded
     * the day to a full rest. A
     * rest day (`$plannedKm <= 0`) is always `Done`; whether something was
     * logged anyway is reported separately via `ran_anyway` rather than
     * changing the status itself.
     *
     * A day still in progress (`$isPast` false) is graded too, but the
     * verdict only stands when the athlete has already earned it: anything
     * short of credited floors back to `Planned`. Falling short is not
     * decidable until the day ends, while clearing the bar cannot be undone
     * by the hours left in it. See
     * `docs/decisions/today-credits-when-earned.md`.
     *
     * @return array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool}
     */
    public static function scoreFor(float $plannedKm, float $completedKm, bool $isPast, bool $excused): array
    {
        if ($excused) {
            return $isPast ? self::verdict(PlannedSessionStatus::Skip) : self::verdict(PlannedSessionStatus::Planned);
        }
        if ($plannedKm <= 0.0) {
            return $isPast
                ? ['status' => PlannedSessionStatus::Done, 'score' => null, 'ran_anyway' => $completedKm > 0.0]
                : self::verdict(PlannedSessionStatus::Planned);
        }

        $ratio = $completedKm / $plannedKm;
        $status = match (true) {
            $ratio >= self::OVERREACHED_FRACTION => PlannedSessionStatus::Overreached,
            $ratio >= self::DONE_FRACTION => PlannedSessionStatus::Done,
            $ratio >= self::PARTIAL_FRACTION => PlannedSessionStatus::Partial,
            default => PlannedSessionStatus::Missed,
        };

        if (! $isPast && ! $status->isCredited()) {
            return self::verdict(PlannedSessionStatus::Planned);
        }

        return ['status' => $status, 'score' => (int) round($ratio * 100), 'ran_anyway' => false];
    }

    /**
     * @return array{status: PlannedSessionStatus, score: int|null, ran_anyway: bool}
     */
    private static function verdict(PlannedSessionStatus $status): array
    {
        return ['status' => $status, 'score' => null, 'ran_anyway' => false];
    }

    /**
     * Every logged run of each day in the range, keyed by date, oldest
     * first. `km` is the day's total (the same figure {@see self::scoreFor()}
     * grades against) and drives the planned-vs-actual bar; `runs` lists each
     * run so a two-session day can show both instead of pairing the day's
     * total distance with one run's duration, which read as a single
     * impossible run.
     *
     * @return array<string, array{km: float, runs: list<array{id: int, km: float, seconds: int|null}>}>
     */
    public function activityByDate(User $user, Carbon $from, Carbon $to): array
    {
        if ($to->lessThan($from)) {
            return [];
        }

        $rows = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [$from->copy()->startOfDay(), $to->copy()->endOfDay()])
            ->orderBy('activity_details.start_date_local')
            ->get(['activity_details.activity_id', 'activity_details.start_date_local', 'activity_details.distance', 'activity_details.moving_time']);

        $byDate = [];
        foreach ($rows as $row) {
            $date = $row->start_date_local?->toDateString();
            if ($date === null) {
                continue;
            }

            $km = DistanceFormatter::km((float) $row->distance);
            $byDate[$date]['km'] = round(($byDate[$date]['km'] ?? 0.0) + $km, 1);
            $byDate[$date]['runs'][] = [
                'id' => (int) $row->activity_id,
                'km' => $km,
                'seconds' => $row->moving_time,
            ];
        }

        return $byDate;
    }

    /**
     * A day's runs as both figures the scorer can need: everything that day
     * added up, and its single longest run.
     *
     * @param  non-empty-array<string, float>  $plannedKmByDate
     * @return array<string, array{sum: float, longest: float}>  Y-m-d => km run that day
     */
    private function completedKmByDate(User $user, array $plannedKmByDate): array
    {
        $dates = array_keys($plannedKmByDate);

        $rows = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->whereBetween('activity_details.start_date_local', [
                Carbon::parse(min($dates))->startOfDay(),
                Carbon::parse(max($dates))->endOfDay(),
            ])
            ->selectRaw('DATE(activity_details.start_date_local) as d, SUM(activity_details.distance) as meters, MAX(activity_details.distance) as longest')
            ->groupBy('d')
            ->toBase()
            ->get();

        $byDate = [];
        foreach ($rows as $row) {
            /** @var object{d: string, meters: float|string|null, longest: float|string|null} $row */
            $byDate[$row->d] = [
                'sum' => DistanceFormatter::km((float) $row->meters),
                'longest' => DistanceFormatter::km((float) $row->longest),
            ];
        }

        return $byDate;
    }

    /**
     * Which of these dates prescribed a long run. Read here rather than
     * passed in, so the three callers that build `$plannedKmByDate` cannot
     * drift out of step with it.
     *
     * @param  list<string>  $dates
     * @return array<string, bool>
     */
    private function longRunDates(User $user, array $dates): array
    {
        return PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('session_type', SessionType::Long)
            ->whereIn('date', $dates)
            ->get(['date'])
            ->mapWithKeys(static fn (PlannedSession $session): array => [$session->date->toDateString() => true])
            ->all();
    }
}
