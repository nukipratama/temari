<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\Activity;
use App\Models\ActivityDetail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Resolves the whole-history facts a card needs into a single {@see CardContext},
 * so the scoring and badge rules never query.
 *
 * The first-run / first-bracket / weekly-consistency counts are folded into one
 * conditional aggregate: they scan the same per-user rows and differ only in
 * predicate. The first-run count keeps the AnalyzedScope's `analyzed_at` filter,
 * which excludes un-analyzed stubs so a sync backlog cannot suppress the
 * "PertamaKali" badge on the real first run; the first-bracket count deliberately
 * ignores that filter, as the query it replaces did.
 */
final class CardContextBuilder
{
    private const int WEEKLY_CONSISTENCY_RUNS = 3;

    /** Distance brackets (metres) for first-bracket tracking. */
    private const array DISTANCE_BRACKETS = [
        5_000,
        10_000,
        15_000,
        21_097.5,
        42_195.0,
    ];

    /** How far back the streak scan reaches, in distinct run days. */
    private const int STREAK_LOOKBACK_DAYS = 30;

    public function for(Activity $activity, ActivityDetail $detail): CardContext
    {
        $startDate = $activity->detail?->start_date_local;
        $bracket = $this->reachedBracket($detail);
        $counts = $this->historyCounts($activity, $bracket, $startDate);

        return new CardContext(
            isFirstRunEver: $counts['other_activities'] === 0,
            isFirstDistanceBracket: $bracket !== null && $counts['other_at_bracket'] === 0,
            weeklyConsistency: $startDate !== null && $counts['week_runs'] >= self::WEEKLY_CONSISTENCY_RUNS,
            consecutiveDaysBefore: $this->consecutiveDaysBefore($activity, $startDate),
            athleteMaxHr: $detail->average_heartrate !== null
                ? $activity->user->hrProfile()['max_hr']
                : null,
        );
    }

    /**
     * @return array{other_activities: int, other_at_bracket: int, week_runs: int}
     */
    private function historyCounts(Activity $activity, ?float $bracket, ?Carbon $startDate): array
    {
        $weekStart = $startDate?->copy()->startOfWeek(Carbon::MONDAY);
        $weekEnd = $startDate?->copy()->endOfWeek(Carbon::SUNDAY);

        $row = (array) DB::table('activities')
            ->leftJoin('activity_details', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->selectRaw(
                'SUM(CASE WHEN activities.id <> ? AND activities.analyzed_at IS NOT NULL THEN 1 ELSE 0 END) as other_activities, '
                .'SUM(CASE WHEN activities.id <> ? AND activity_details.distance >= ? THEN 1 ELSE 0 END) as other_at_bracket, '
                .'SUM(CASE WHEN activities.analyzed_at IS NOT NULL AND activity_details.start_date_local BETWEEN ? AND ? THEN 1 ELSE 0 END) as week_runs',
                [
                    $activity->id,
                    $activity->id,
                    $bracket ?? 0.0,
                    $weekStart?->toDateTimeString() ?? '1970-01-01 00:00:00',
                    $weekEnd?->toDateTimeString() ?? '1970-01-01 00:00:00',
                ],
            )
            ->first();

        return [
            'other_activities' => (int) ($row['other_activities'] ?? 0),
            'other_at_bracket' => (int) ($row['other_at_bracket'] ?? 0),
            'week_runs' => (int) ($row['week_runs'] ?? 0),
        ];
    }

    /**
     * Count consecutive running days ending the day before this activity.
     * Returns the streak length (0 = no run yesterday).
     */
    private function consecutiveDaysBefore(Activity $activity, ?Carbon $startDate): int
    {
        if ($startDate === null) {
            return 0;
        }

        // Fetch the last 30 distinct run dates in one query, then count
        // consecutive days in PHP. Much cheaper than N queries for long streaks.
        $dates = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $activity->user_id)
            ->whereDate('start_date_local', '<', $startDate->toDateString())
            ->selectRaw('DISTINCT DATE(start_date_local) as run_date')
            ->orderByDesc('run_date')
            ->limit(self::STREAK_LOOKBACK_DAYS)
            ->pluck('run_date')
            ->map(fn (string $d): string => Carbon::parse($d)->toDateString())
            ->flip();

        $streak = 0;
        $checkDate = $startDate->copy()->subDay();

        while (isset($dates[$checkDate->toDateString()])) {
            $streak++;
            $checkDate->subDay();
        }

        return $streak;
    }

    /**
     * The highest standard distance bracket (5K / 10K / 15K / 21K / 42K) this
     * run reaches, or null when it reaches none.
     */
    private function reachedBracket(ActivityDetail $detail): ?float
    {
        $distance = (float) ($detail->distance ?? 0);
        if ($distance <= 0) {
            return null;
        }

        $reached = null;
        foreach (self::DISTANCE_BRACKETS as $bracket) {
            if ($distance >= $bracket) {
                $reached = (float) $bracket;
            }
        }

        return $reached;
    }
}
