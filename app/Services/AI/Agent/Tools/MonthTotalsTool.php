<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\ActivityDetail;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PaceFormatter;
use App\Services\Run\Story\MoodMix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything a monthly recap counts, for one calendar month, and the calendar month before it to
 * measure against.
 *
 * The month is a discriminator string (`Y-m`), not a date range the model gets
 * to choose, so a recap can only ever count the month it was asked about.
 */
final class MonthTotalsTool extends NoArgumentTool
{
    public function __construct(
        private readonly User $user,
        private readonly string $month,
    ) {
    }

    public function name(): string
    {
        return 'get_month_totals';
    }

    public function description(): string
    {
        return "The recap for the month you're telling: total runs and km, longest run, PR count, "
            .'km per week within that month, mood distribution, and the long-term load (ctl_start vs '
            .'ctl_end plus load_balance_end), plus the previous month\'s numbers to compare against. '
            .'pace_formatted and prev_pace_formatted (mm:ss/km) are the only forms to quote; the '
            .'_sec_per_km twins are raw seconds, for judging size, never for quoting. If prev_* is '
            ."null, the previous month has no runs, so there's no comparison. An empty mood_mix "
            .'means there\'s no mood data yet, so skip the mood section silently. If long_term_load '
            .'is missing, that month has no snapshot.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        [$start, $end] = MonthRunsTool::bounds($this->month);

        $details = self::runsIn($this->user, $start, $end);
        $distance = self::distanceMetersOf($details);
        $pace = self::paceOf($details);
        $longestDistance = $details->max('distance');
        assert($longestDistance === null || is_numeric($longestDistance));

        $previous = self::runsIn($this->user, ...MonthRunsTool::bounds($start->copy()->subMonthNoOverflow()->format('Y-m')));
        $prevPace = self::paceOf($previous);

        return [
            'month' => $this->month,
            'total_runs' => $details->count(),
            'total_distance_km' => DistanceFormatter::km($distance),
            'pace_sec_per_km' => $pace,
            'pace_formatted' => $pace === null ? null : PaceFormatter::format($pace),
            'longest_run_km' => DistanceFormatter::km((float) $longestDistance, DistanceFormatter::EXACT),
            'pr_count' => PeriodRecordsTool::recordsBetween($this->user, $start, $end)->count(),
            'weekly_distance_km' => self::weeklyDistanceKm($details, $start),
            // Half-open, so the exclusive bound is the next month's start rather
            // than this month's inclusive end -- passing $end would drop a run
            // logged in the final second.
            'mood_mix' => MoodMix::between($this->user->id, $start, $start->copy()->addMonth()->startOfMonth()),
            'long_term_load' => $this->longTermLoadArc($start, $end),
            'prev_runs' => $previous->isEmpty() ? null : $previous->count(),
            'prev_distance_km' => $previous->isEmpty() ? null : DistanceFormatter::km(self::distanceMetersOf($previous)),
            'prev_pace_sec_per_km' => $prevPace,
            'prev_pace_formatted' => $prevPace === null ? null : PaceFormatter::format($prevPace),
        ];
    }

    /** @return Collection<int, ActivityDetail> */
    private static function runsIn(User $user, Carbon $start, Carbon $end): Collection
    {
        return PeriodRunsTool::detailsBetween($user, $start, $end)
            ->get(['activity_details.start_date_local', 'activity_details.distance', 'activity_details.elapsed_time']);
    }

    /** @param  Collection<int, ActivityDetail>  $details */
    private static function distanceMetersOf(Collection $details): float
    {
        $meters = $details->sum('distance');
        assert(is_numeric($meters));

        return (float) $meters;
    }

    /** @param  Collection<int, ActivityDetail>  $details */
    private static function paceOf(Collection $details): ?float
    {
        $seconds = $details->sum('elapsed_time');
        assert(is_numeric($seconds));

        return PaceCalculator::secPerKm(self::distanceMetersOf($details), (float) $seconds);
    }

    /**
     * Distance per seven-day bucket from the month's start, so the model can
     * read "climbing every week" without doing the bucketing itself.
     *
     * @param  Collection<int, ActivityDetail>  $details
     * @return list<float>
     */
    private static function weeklyDistanceKm(Collection $details, Carbon $start): array
    {
        $buckets = [];
        foreach ($details as $detail) {
            if ($detail->start_date_local === null) {
                continue;
            }
            $week = intdiv((int) $start->diffInDays($detail->start_date_local, absolute: false), 7);
            $buckets[$week] = ($buckets[$week] ?? 0.0) + (float) ($detail->distance ?? 0);
        }

        if ($buckets === []) {
            return [];
        }

        ksort($buckets);
        $weeks = [];
        for ($i = 0; $i <= max(array_keys($buckets)); $i++) {
            $weeks[] = DistanceFormatter::km($buckets[$i] ?? 0.0);
        }

        return $weeks;
    }

    /**
     * The month's long-term load arc from the weekly snapshots ending within it: CTL at
     * the start vs end, and the closing load balance.
     *
     * @return array{ctl_start: float|null, ctl_end: float|null, load_balance_end: string|null}|null
     */
    private function longTermLoadArc(Carbon $start, Carbon $end): ?array
    {
        $snapshots = WeeklySnapshot::query()
            ->where('user_id', $this->user->id)
            ->whereBetween('week_ending', [$start, $end])
            ->orderBy('week_ending')
            ->get(['ctl_42d', 'form_status']);

        if ($snapshots->isEmpty()) {
            return null;
        }

        return [
            'ctl_start' => $snapshots->first()->ctl_42d,
            'ctl_end' => $snapshots->last()->ctl_42d,
            'load_balance_end' => $snapshots->last()->form_status?->loadBalance()->value,
        ];
    }
}
