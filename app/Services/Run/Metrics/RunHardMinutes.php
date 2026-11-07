<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

use App\Actions\Run\Plan\ResolveWeekAdaptationAction;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What one run spent from its week's hard-minute budget, read with the same
 * easy ceiling (marathon pace) the budget counts with, and which of its minutes
 * counted.
 */
final readonly class RunHardMinutes
{
    public function __construct(
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $paceCalculator,
        private ResolveWeekAdaptationAction $weekAdaptation,
    ) {
    }

    /**
     * @return array{minutes: float, hr_only_minutes: float, cap_minutes: int|null, easy_ceiling_sec_per_km: int|null, per_km: list<array{km: int, easy_sec: int, high_hr_easy_pace_sec: int, hard_sec: int}>|null}|null
     */
    public function for(User $user, ActivityDetail $detail): ?array
    {
        $summary = StreamSummary::fromArray($detail->streamSummary());
        $hrOnlyMinutes = $summary->hardMinutes(null);
        if ($hrOnlyMinutes === null || ! $detail->start_date_local instanceof Carbon) {
            return null;
        }

        $weekStart = $detail->start_date_local->copy()->startOfWeek(Carbon::MONDAY);
        $asOf = Carbon::now()->min($weekStart->copy()->endOfWeek(Carbon::SUNDAY));
        $easyCeiling = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user, $asOf))['marathon'] ?? null;

        return [
            'minutes' => $summary->hardMinutes($easyCeiling) ?? $hrOnlyMinutes,
            'hr_only_minutes' => $hrOnlyMinutes,
            'cap_minutes' => ($this->weekAdaptation)($user->id, $weekStart->toDateString())?->hard_cap_minutes,
            'easy_ceiling_sec_per_km' => $easyCeiling,
            'per_km' => $summary->hardSecondsByKm($easyCeiling),
        ];
    }
}
