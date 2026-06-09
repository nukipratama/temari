<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use Illuminate\Support\Collection;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrainingLoad
{
    /** Time constants (days) for the EWMA decay. */
    private const int ATL_TAU = 7;

    private const int CTL_TAU = 42;

    /**
     * Lower bound (days) on how far back the EWMA loads TRIMP. At 365 days a
     * 42-day-tau CTL has converged to within ~0.02% of its steady state, so the
     * cap is indistinguishable from full history yet keeps the query (and the
     * roll loop) bounded instead of scanning a multi-year history every call.
     */
    public const int CONVERGED_LOOKBACK_DAYS = 365;

    /**
     * @param  array<string, float|int>  $timeInZoneMin  zone name → minutes
     */
    public function edwardsTrimp(array $timeInZoneMin): float
    {
        $sum = 0.0;
        foreach ($timeInZoneMin as $zone => $minutes) {
            $weight = $this->zoneWeight($zone);
            $sum += $weight * (float) $minutes;
        }

        return round($sum, 1);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function summary(User $user, ?Carbon $asOf = null): ?array
    {
        $today = ($asOf ?? Carbon::today())->copy()->startOfDay();
        $dailyTrimp = $this->loadDailyTrimp($user, $today);
        if ($dailyTrimp === []) {
            return null;
        }

        return $this->summaryFromDailyMap($dailyTrimp, $today);
    }

    /**
     * $asOf anchors the week window (weekly_trimp / monotony / strain). $loadAsOf
     * anchors the ATL/CTL roll and defaults to $asOf; callers pass an earlier
     * date to measure fitness as-of a day that is not the week's end (e.g. the
     * in-progress week, capped at today, so future days are not zero-filled).
     *
     * @param  array<string, float>  $dailyTrimp
     * @return array<string, mixed>|null
     */
    public function summaryFromDailyMap(array $dailyTrimp, Carbon $asOf, ?Carbon $loadAsOf = null): ?array
    {
        if ($dailyTrimp === []) {
            return null;
        }

        $weekAnchor = $asOf->copy()->startOfDay();
        $loadDate = ($loadAsOf ?? $asOf)->copy()->startOfDay();
        [$atl, $ctl] = $this->rollLoads($dailyTrimp, $loadDate);
        $form = round($ctl - $atl, 1);
        [$weeklyTrimp, $monotony, $strain] = $this->weekStats($dailyTrimp, $weekAnchor);

        return [
            'weekly_trimp' => round($weeklyTrimp, 1),
            'atl_7d' => round($atl, 1),
            'ctl_42d' => round($ctl, 1),
            'form' => $form,
            'form_status' => $this->formStatus($form, $ctl),
            'monotony' => $monotony,
            'strain' => $strain,
        ];
    }

    /**
     * Loads the full TRIMP history from the user's first-ever activity so the
     * EWMA in rollLoads converges as a continuous, window-independent series.
     *
     * @return array<string, float>  date (Y-m-d) → summed TRIMP for that day
     */
    private function loadDailyTrimp(User $user, Carbon $asOf): array
    {
        /** @var Collection<int, object{dt: string, trimp_sum: float}> $rows */
        $rows = ActivityDetail::query()
            ->join('activities', 'activities.id', '=', 'activity_details.activity_id')
            ->where('activities.user_id', $user->id)
            ->whereNotNull('activity_details.trimp_edwards')
            ->whereNotNull('activity_details.start_date_local')
            ->where('activity_details.start_date_local', '>=', $asOf->copy()->subDays(self::CONVERGED_LOOKBACK_DAYS)->startOfDay())
            ->where('activity_details.start_date_local', '<=', $asOf->copy()->endOfDay())
            ->groupBy(DB::raw('DATE(activity_details.start_date_local)'))
            ->orderBy('dt')
            ->get([
                DB::raw('DATE(activity_details.start_date_local) as dt'),
                DB::raw('SUM(activity_details.trimp_edwards) as trimp_sum'),
            ]);

        return $rows->mapWithKeys(fn (object $r): array => [
            $r->dt => (float) $r->trimp_sum,
        ])->all();
    }

    public function formStatus(float $form, float $ctl): string
    {
        $threshold = match (true) {
            $ctl < 20 => 5.0,
            $ctl <= 50 => 15.0,
            default => 20.0,
        };

        return match (true) {
            $form > $threshold => 'fresh',
            $form > -$threshold => 'optimal',
            $form > -$threshold * 2 => 'fatigued',
            default => 'overreaching',
        };
    }

    /**
     * Rolls a continuous ATL/CTL EWMA from the first day of the supplied map
     * through $today. Missing days contribute zero TRIMP so a rest day reduces
     * fatigue but doesn't reduce fitness. Callers pass the full TRIMP history
     * (from the first-ever activity), so the EWMA state converges and the
     * result is independent of any window length.
     *
     * @param  array<string, float>  $dailyTrimp
     * @return array{0: float, 1: float}
     */
    private function rollLoads(array $dailyTrimp, Carbon $today): array
    {
        $decayAtl = exp(-1.0 / self::ATL_TAU);
        $decayCtl = exp(-1.0 / self::CTL_TAU);
        $atl = 0.0;
        $ctl = 0.0;

        $startDate = Carbon::parse((string) array_key_first($dailyTrimp));
        $cursor = $startDate->copy();
        while ($cursor->lte($today)) {
            $trimp = $dailyTrimp[$cursor->toDateString()] ?? 0.0;
            $atl = $atl * $decayAtl + $trimp * (1 - $decayAtl);
            $ctl = $ctl * $decayCtl + $trimp * (1 - $decayCtl);
            $cursor->addDay();
        }

        return [$atl, $ctl];
    }

    /**
     * @param  array<string, float>  $dailyTrimp
     * @return array{0: float, 1: float, 2: float}  weekly_trimp, monotony, strain
     */
    private function weekStats(array $dailyTrimp, Carbon $today): array
    {
        $week = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = $today->copy()->subDays($i)->toDateString();
            $week[] = $dailyTrimp[$date] ?? 0.0;
        }

        $weekly = array_sum($week);
        if ($weekly <= 0) {
            return [0.0, 0.0, 0.0];
        }
        $mean = $weekly / 7;
        $variance = array_sum(array_map(fn (float $t): float => ($t - $mean) ** 2, $week)) / 7;
        $sd = sqrt($variance);

        // Cap at 5.0 when sd≈0 (uniform-load week) instead of dividing by zero.
        $monotony = $sd > 0.01 ? min(5.0, round($mean / $sd, 2)) : 5.0;
        $strain = round($weekly * $monotony, 1);

        return [$weekly, $monotony, $strain];
    }

    private function zoneWeight(string $zone): int
    {
        return match (strtoupper($zone)) {
            'Z1' => 1,
            'Z2' => 2,
            'Z3' => 3,
            'Z4' => 4,
            'Z5' => 5,
            default => 0,
        };
    }
}
