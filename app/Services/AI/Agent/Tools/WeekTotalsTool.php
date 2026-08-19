<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\PaceCalculator;

/**
 * The week a recap is about, and the calendar week before it to measure
 * against. The comparison week is looked up only if the model asks for it.
 */
final class WeekTotalsTool extends NoArgumentTool
{
    public function __construct(private readonly WeeklySnapshot $snapshot)
    {
    }

    public function name(): string
    {
        return 'get_week_totals';
    }

    public function description(): string
    {
        return "The week you're telling: number of runs, distance, average pace, TRIMP, "
            .'ctl_42d/atl_7d/form/form_status, monotony, strain, average decoupling, plus the '
            ."previous week's numbers to compare against. If prev_* is missing, there's no "
            .'comparison week yet. weekly_trimp, monotony and strain are null when no run that '
            .'week carried heart rate: the load is unknown, which is not the same as zero.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $previous = WeeklySnapshot::query()
            ->where('user_id', $this->snapshot->user_id)
            ->whereDate('week_ending', $this->snapshot->week_ending->copy()->subWeek())
            ->first();

        return [
            'week_ending' => $this->snapshot->week_ending->toDateString(),
            'runs' => $this->snapshot->runs,
            'distance_km' => $this->snapshot->distance_km,
            'pace_sec_per_km' => self::paceFor($this->snapshot),
            'weekly_trimp' => $this->snapshot->weekly_trimp,
            'ctl_42d' => $this->snapshot->ctl_42d,
            'atl_7d' => $this->snapshot->atl_7d,
            'form' => $this->snapshot->form,
            'form_status' => $this->snapshot->form_status,
            'monotony' => $this->snapshot->monotony,
            'strain' => $this->snapshot->strain,
            'avg_decoupling' => $this->snapshot->avg_decoupling,
            'prev_runs' => $previous?->runs,
            'prev_distance_km' => $previous?->distance_km,
            'prev_pace_sec_per_km' => $previous === null ? null : self::paceFor($previous),
        ];
    }

    private static function paceFor(WeeklySnapshot $snapshot): ?float
    {
        return PaceCalculator::secPerKm(
            $snapshot->distance_km === null ? null : $snapshot->distance_km * 1000,
            $snapshot->moving_time_sec,
        );
    }
}
