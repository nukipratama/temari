<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\ActivityDetail;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Support\Carbon;

/**
 * The range a Trends narration is about (30d/90d/12mo), read as of now.
 *
 * For 30d/90d the comparison is against the immediately preceding period of
 * the same length. 12mo is different on purpose: comparing this year against
 * the year before it needs history most users don't have yet, so it instead
 * splits its own window in half and compares the second half against the
 * first — same shape (`current` vs `comparison`), different boundaries.
 */
final class TrendRangeTool extends NoArgumentTool
{
    /** @var array<string, int> */
    private const array RANGE_DAYS = ['30d' => 30, '90d' => 90, '12mo' => 365];

    public function __construct(
        private readonly User $user,
        private readonly string $range,
        private readonly TrainingLoad $trainingLoad,
    ) {
    }

    public function name(): string
    {
        return 'get_trend_range_totals';
    }

    public function description(): string
    {
        return "The range you're reading: distance/runs/TRIMP for the current period and the "
            .'comparison period (the same length before it, or, for the 12mo range only, the '
            .'first half of this same window, since the second half is `current`), CTL at the '
            .'start and end of the window, VDOT at the start and end (null if no history yet), '
            .'and the average monotony/strain across the current period. weekly_trimp-derived '
            .'fields are null where the underlying week is unscored, which is not the same as zero.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $days = self::RANGE_DAYS[$this->range] ?? self::RANGE_DAYS['30d'];
        $today = Carbon::today();

        if ($this->range === '12mo') {
            $currentStart = $today->copy()->subDays((int) floor($days / 2) - 1);
            $comparisonStart = $today->copy()->subDays($days - 1);
            $comparisonEnd = $currentStart->copy()->subDay();
        } else {
            $currentStart = $today->copy()->subDays($days - 1);
            $comparisonStart = $currentStart->copy()->subDays($days);
            $comparisonEnd = $currentStart->copy()->subDay();
        }

        $ctlSeries = $this->trainingLoad->ctlTrend($this->user, $days);
        $strainSeries = $this->trainingLoad->strainMonotonyTrend($this->user, $days);

        return [
            'range' => $this->range,
            'current' => $this->periodTotals($currentStart, $today),
            'comparison' => $this->periodTotals($comparisonStart, $comparisonEnd),
            'ctl_start' => $ctlSeries === [] ? null : $ctlSeries[0]['ctl'],
            'ctl_end' => $ctlSeries === [] ? null : array_last($ctlSeries)['ctl'],
            'vdot_start' => $this->vdotNear($currentStart, ascending: true),
            'vdot_end' => $this->vdotNear($today, ascending: false),
            'avg_monotony' => $this->average($strainSeries, 'monotony'),
            'avg_strain' => $this->average($strainSeries, 'strain'),
        ];
    }

    /** @return array{runs: int, distance_km: float, trimp_total: float|null} */
    private function periodTotals(Carbon $start, Carbon $end): array
    {
        /** @var object{runs: int, distance_m: float|null, trimp_total: float|null}|null $row */
        $row = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $this->user->id)
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $start->copy()->startOfDay())
            ->where('activity_details.start_date_local', '<=', $end->copy()->endOfDay())
            ->selectRaw('COUNT(*) as runs, SUM(activity_details.distance) as distance_m, SUM(activity_details.trimp_edwards) as trimp_total')
            ->first();

        return [
            'runs' => (int) ($row->runs ?? 0),
            'distance_km' => $row === null || $row->distance_m === null ? 0.0 : round(((float) $row->distance_m) / 1000, 1),
            'trimp_total' => $row === null || $row->trimp_total === null ? null : round((float) $row->trimp_total, 1),
        ];
    }

    private function vdotNear(Carbon $date, bool $ascending): ?float
    {
        $query = TrendDailySnapshot::query()
            ->where('user_id', $this->user->id)
            ->whereNotNull('vdot');

        $value = $ascending
            ? $query->where('snapshot_date', '>=', $date->toDateString())->orderBy('snapshot_date')->value('vdot')
            : $query->where('snapshot_date', '<=', $date->toDateString())->orderByDesc('snapshot_date')->value('vdot');

        return $value === null ? null : (float) $value;
    }

    /**
     * @param  list<array{date: string, weekly_trimp: float|null, monotony: float|null, strain: float|null}>  $series
     */
    private function average(array $series, string $key): ?float
    {
        $values = array_values(array_filter(
            array_map(static fn (array $point): mixed => $point[$key], $series),
            static fn (mixed $v): bool => $v !== null,
        ));

        return $values === [] ? null : round(array_sum($values) / count($values), 2);
    }
}
