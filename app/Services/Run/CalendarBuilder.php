<?php

declare(strict_types=1);

namespace App\Services\Run;

use App\Models\ActivityDetail;
use App\Models\StoryLine;
use App\Models\User;
use App\Services\Run\Metrics\PaceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the per-day cell grid for the /kalender month view. Each cell carries
 * the day's aggregated distance / pace / weighted HR / TRIMP / mood so the
 * frontend renders rich detail without a second query. The grid spans whole
 * Mon-Sun weeks ($gridStart..$gridEnd) padded around the visible month.
 */
class CalendarBuilder
{
    /**
     * @return array<int, array{date: string, day: int, is_current_month: bool, is_today: bool, distance_km: float|null, pace_sec_per_km: float|null, avg_hr: int|null, trimp: float|null, mood: string|null, activity_id: int|null}>
     */
    public function buildCells(User $user, Carbon $gridStart, Carbon $gridEnd, Carbon $monthStart, Carbon $monthEnd): array
    {
        $details = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activities.analyzed_at')
            ->whereBetween('activity_details.start_date_local', [$gridStart, $gridEnd])
            ->select([
                'activities.id as activity_id',
                'activity_details.start_date_local',
                'activity_details.distance',
                'activity_details.moving_time',
                'activity_details.average_heartrate',
                'activity_details.trimp_edwards',
            ])
            ->get();

        $activityIds = $details->pluck('activity_id')->all();
        $moodByActivity = $this->moodsForActivities($activityIds);

        $byDay = $details->groupBy(fn ($row): string => Carbon::parse($row->start_date_local)->toDateString());

        $cells = [];
        $cursor = $gridStart->copy();
        $todayKey = Carbon::today()->toDateString();
        while ($cursor->lessThanOrEqualTo($gridEnd)) {
            $dateKey = $cursor->toDateString();
            $cells[] = $this->cellFor($cursor, $dateKey, $byDay->get($dateKey), $moodByActivity, $monthStart, $monthEnd, $todayKey);
            $cursor->addDay();
        }

        return $cells;
    }

    /**
     * @param  Collection<int, ActivityDetail>|null  $rows
     * @param  array<int, string>  $moodByActivity
     * @return array{date: string, day: int, is_current_month: bool, is_today: bool, distance_km: float|null, pace_sec_per_km: float|null, avg_hr: int|null, trimp: float|null, mood: string|null, activity_id: int|null}
     */
    private function cellFor(Carbon $cursor, string $dateKey, ?Collection $rows, array $moodByActivity, Carbon $monthStart, Carbon $monthEnd, string $todayKey): array
    {
        $base = [
            'date' => $dateKey,
            'day' => $cursor->day,
            'is_current_month' => $cursor->betweenIncluded($monthStart, $monthEnd),
            'is_today' => $dateKey === $todayKey,
        ];

        if ($rows === null || $rows->isEmpty()) {
            return [
                ...$base,
                'distance_km' => null,
                'pace_sec_per_km' => null,
                'avg_hr' => null,
                'trimp' => null,
                'mood' => null,
                'activity_id' => null,
            ];
        }

        $totalDistance = (float) $rows->sum(fn ($r) => (float) ($r->distance ?? 0));
        $totalMoving = (float) $rows->sum(fn ($r) => (float) ($r->moving_time ?? 0));
        $totalTrimp = (float) $rows->sum(fn ($r) => (float) ($r->trimp_edwards ?? 0));

        // Weighted average HR by moving time so longer runs dominate the day's reading.
        $hrWeighted = 0.0;
        $hrWeight = 0.0;
        foreach ($rows as $r) {
            if ($r->average_heartrate !== null && $r->moving_time !== null && $r->moving_time > 0) {
                $hrWeighted += (float) $r->average_heartrate * (float) $r->moving_time;
                $hrWeight += (float) $r->moving_time;
            }
        }

        // `$rows` is non-empty (guarded above) so `first()` is always a model.
        $primary = $rows->first();
        $primaryId = (int) $primary->getAttribute('activity_id');

        $paceSecPerKm = PaceCalculator::secPerKm($totalDistance, $totalMoving);

        return [
            ...$base,
            'distance_km' => round($totalDistance / 1000, 2),
            'pace_sec_per_km' => $paceSecPerKm !== null ? round($paceSecPerKm, 0) : null,
            'avg_hr' => $hrWeight > 0 ? (int) round($hrWeighted / $hrWeight) : null,
            'trimp' => round($totalTrimp, 1),
            'mood' => $moodByActivity[$primaryId] ?? null,
            'activity_id' => $rows->count() === 1 ? $primaryId : null,
        ];
    }

    /**
     * @param  array<int, int>  $activityIds
     * @return array<int, string>
     */
    private function moodsForActivities(array $activityIds): array
    {
        if ($activityIds === []) {
            return [];
        }

        return StoryLine::query()
            ->where('kind', StoryLine::KIND_POST_RUN)
            ->whereIn('activity_id', $activityIds)
            ->pluck('mood', 'activity_id')
            ->all();
    }
}
