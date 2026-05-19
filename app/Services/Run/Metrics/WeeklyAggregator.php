<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\UnlockEngine;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

use function count;
use function is_array;

class WeeklyAggregator
{
    public function __construct(
        private readonly TrainingLoad $trainingLoad,
        private readonly UnlockEngine $unlockEngine,
    ) {
    }

    public function rebuildForWeekOf(User $user, Carbon $when): ?WeeklySnapshot
    {
        $weekEnding = $when->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        // 49 days = 7 weeks, comfortably covers the 42-day CTL window.
        $windowStart = $weekEnding->copy()->subDays(49)->startOfDay();

        /** @var Collection<int, ActivityDetail> $details */
        $details = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereBetween('activity_details.start_date_local', [$windowStart, $weekEnding->copy()->endOfDay()])
            ->orderBy('activity_details.start_date_local')
            ->select('activity_details.*')
            ->get();

        if ($details->isEmpty()) {
            return null;
        }

        $dailyTrimp = $this->dailyTrimpMap($details);
        $this->upsertWeek($user, $weekEnding, $details, $dailyTrimp);

        return WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('week_ending', $weekEnding->toDateString())
            ->first();
    }

    public function rebuildFor(User $user): int
    {
        /** @var Collection<int, ActivityDetail> $details */
        $details = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->orderBy('activity_details.start_date_local')
            ->select('activity_details.*')
            ->get();

        if ($details->isEmpty()) {
            return 0;
        }

        $dailyTrimp = $this->dailyTrimpMap($details);

        /** @var Carbon $earliest */
        $earliest = $details->first()->start_date_local;
        $weekEnding = $earliest->copy()->endOfWeek(Carbon::SUNDAY)->startOfDay();
        $today = Carbon::today()->endOfWeek(Carbon::SUNDAY)->startOfDay();

        $count = 0;
        while ($weekEnding->lte($today)) {
            $this->upsertWeek($user, $weekEnding, $details, $dailyTrimp);
            $weekEnding = $weekEnding->copy()->addWeek();
            $count++;
        }

        $this->unlockEngine->grantEligible($user);

        return $count;
    }

    /**
     * @param  Collection<int, ActivityDetail>  $details
     * @param  array<string, float>  $dailyTrimp
     */
    private function upsertWeek(User $user, Carbon $weekEnding, Collection $details, array $dailyTrimp): void
    {
        $weekStart = $weekEnding->copy()->subDays(6)->startOfDay();
        $weekEnd = $weekEnding->copy()->endOfDay();

        $weekDetails = $details->filter(
            fn (ActivityDetail $d): bool => $d->start_date_local !== null
                && $d->start_date_local->between($weekStart, $weekEnd),
        );

        $distanceKm = round(((float) $weekDetails->sum('distance')) / 1000, 1);
        $runs = $weekDetails->count();
        $avgDecoupling = $this->averageDecoupling($weekDetails);

        $summary = $this->trainingLoad->summaryFromDailyMap($dailyTrimp, $weekEnding);

        WeeklySnapshot::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'week_ending' => $weekEnding->toDateString(),
            ],
            [
                'distance_km' => $distanceKm,
                'runs' => $runs,
                'weekly_trimp' => $summary['weekly_trimp'] ?? 0.0,
                'atl_7d' => $summary['atl_7d'] ?? 0.0,
                'ctl_42d' => $summary['ctl_42d'] ?? 0.0,
                'form' => $summary['form'] ?? 0.0,
                'form_status' => $summary['form_status'] ?? null,
                'avg_decoupling' => $avgDecoupling,
                'monotony' => $summary['monotony'] ?? 0.0,
                'strain' => $summary['strain'] ?? 0.0,
            ],
        );
    }

    /**
     * @param  Collection<int, ActivityDetail>  $details
     * @return array<string, float>
     */
    private function dailyTrimpMap(Collection $details): array
    {
        $map = [];
        foreach ($details as $detail) {
            if ($detail->trimp_edwards === null || $detail->start_date_local === null) {
                continue;
            }
            $key = $detail->start_date_local->toDateString();
            $map[$key] = ($map[$key] ?? 0.0) + (float) $detail->trimp_edwards;
        }
        ksort($map);

        return $map;
    }

    /**
     * @param  Collection<int, ActivityDetail>  $details
     */
    private function averageDecoupling(Collection $details): ?float
    {
        $values = [];
        foreach ($details as $detail) {
            $summary = $detail->stream_summary;
            if (! is_array($summary)) {
                continue;
            }
            if (isset($summary['decoupling_pct']) && is_numeric($summary['decoupling_pct'])) {
                $values[] = (float) $summary['decoupling_pct'];
            }
        }

        if ($values === []) {
            return null;
        }

        return round(array_sum($values) / count($values), 2);
    }
}
