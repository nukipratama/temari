<?php

declare(strict_types=1);

namespace App\Services\AI\Agent\Tools;

use App\Models\PlannedSession;
use App\Services\Run\Plan\SegmentGenerator;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Support\Carbon;

/**
 * The prescribed session for one day: type, phase, and a rough core
 * distance. Deliberately the unredistributed, multiplier-1.0 figure rather
 * than {@see \App\Http\Controllers\PlanController}'s exact render-time
 * number: the day's plan can still shift before it's actually run, so the
 * narration only needs to be qualitatively right, not pixel-matched to a
 * number the UI itself may later redistribute.
 */
final class PlanDayTool extends NoArgumentTool
{
    public function __construct(
        private readonly PlannedSession $session,
        private readonly TrainingBaseline $baseline,
    ) {
    }

    public function name(): string
    {
        return 'get_day_plan';
    }

    public function description(): string
    {
        return 'The prescribed session for this day: type (easy/long/tempo/interval/rest), '
            .'training phase, and an approximate distance in km. skipped true means the athlete '
            .'has already excused themselves from this day.';
    }

    /** @return array<string, mixed> */
    public function handle(array $arguments): array
    {
        $baselineData = $this->baseline->forUser($this->session->user, Carbon::today());
        $coreKm = SegmentGenerator::coreKmFor(
            $this->session->session_type,
            isPrimaryEasy: false,
            longRunBaselineKm: $baselineData['long_run_km'],
            volumeMultiplier: 1.0,
        );

        return [
            'date' => $this->session->date->toDateString(),
            'session_type' => $this->session->session_type->value,
            'phase' => $this->session->phase->value,
            'distance_km' => round($coreKm, 1),
            'skipped' => $this->session->skipped,
        ];
    }
}
