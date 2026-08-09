<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Story\MoodMix;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything a monthly recap counts, for one calendar month.
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
            .'km per week within that month, mood distribution, and fitness direction (ctl_start vs '
            ."ctl_end plus form_status_end). An empty mood_mix means there's no mood data yet, so "
            .'skip the mood section silently. If fitness is missing, that month has no snapshot.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        [$start, $end] = self::bounds($this->month);

        $details = ActivityDetail::query()
            ->whereHas('activity', fn ($query) => $query->where('user_id', $this->user->id))
            ->whereBetween('start_date_local', [$start, $end])
            ->get(['start_date_local', 'distance']);

        return [
            'month' => $this->month,
            'total_runs' => $details->count(),
            'total_distance_km' => DistanceFormatter::km((float) $details->sum('distance')),
            'longest_run_km' => DistanceFormatter::km((float) $details->max('distance'), DistanceFormatter::EXACT),
            'pr_count' => PersonalRecord::query()
                ->where('user_id', $this->user->id)
                ->whereBetween('set_at', [$start, $end])
                ->count(),
            'weekly_distance_km' => self::weeklyDistanceKm($details, $start),
            // Half-open, so the exclusive bound is the next month's start rather
            // than this month's inclusive end -- passing $end would drop a run
            // logged in the final second.
            'mood_mix' => MoodMix::between($this->user->id, $start, $start->copy()->addMonth()->startOfMonth()),
            'fitness' => $this->fitnessArc($start, $end),
        ];
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private static function bounds(string $month): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)?->startOfMonth() ?? Carbon::now()->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
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
     * The month's fitness arc from the weekly snapshots ending within it: CTL at
     * the start vs end, and the closing form_status.
     *
     * @return array{ctl_start: float|null, ctl_end: float|null, form_status_end: string|null}|null
     */
    private function fitnessArc(Carbon $start, Carbon $end): ?array
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
            'form_status_end' => $snapshots->last()->form_status,
        ];
    }
}
