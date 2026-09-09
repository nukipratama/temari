<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Services\AI\Context\ActivityNarrationContext;
use App\Services\Run\Metrics\DistanceFormatter;
use App\Services\Run\Metrics\DurationFormatter;
use App\Services\Run\Metrics\PaceCalculator;
use App\Services\Run\Metrics\PaceFormatter;

final class RunSummaryTool extends ActivityTool
{
    public function name(): string
    {
        return 'get_run_summary';
    }

    public function description(): string
    {
        return "This session's core numbers: when the run was, distance, duration, pace, average "
            .'and max HR, cadence, and cadence_drop_spm (how much step rate fell from the first half '
            .'to the second). moving_time_formatted (h:mm:ss) and pace_formatted (mm:ss/km) are the '
            .'only forms to quote -- matches what the app shows; moving_time_sec and pace_sec_per_km '
            .'are raw seconds, for judging size, never for quoting. Start here.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $shared = ActivityNarrationContext::fromDetail($this->detail);
        $paceSecPerKm = PaceCalculator::secPerKm($shared->distanceMeters, $this->detail->moving_time);

        return [
            'started_at_local' => $this->detail->start_date_local?->toDateTimeString(),
            'distance_km' => $shared->distanceKm(DistanceFormatter::COPY),
            'moving_time_sec' => $this->detail->moving_time,
            'moving_time_formatted' => $this->detail->moving_time === null
                ? null
                : DurationFormatter::hms($this->detail->moving_time),
            'pace_sec_per_km' => $paceSecPerKm !== null ? round($paceSecPerKm, 1) : null,
            'pace_formatted' => $paceSecPerKm !== null ? PaceFormatter::format($paceSecPerKm) : null,
            'avg_hr' => $this->detail->average_heartrate,
            'max_hr' => $this->detail->max_heartrate,
            'avg_cadence_spm' => $this->detail->average_cadence !== null
                ? (int) round((float) $this->detail->average_cadence * 2)
                : null,
            'cadence_drop_spm' => $this->summary()->cadenceDropSpm(),
        ];
    }
}
