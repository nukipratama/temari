<?php

declare(strict_types=1);

namespace App\Services\Run\Metrics;

/**
 * A deterministic ceiling plus the evidence that produced it. Missing data is
 * recorded as unknown; it does not become a fatigue signal.
 */
final readonly class Readiness
{
    /**
     * @param  list<string>  $reasons
     * @param  array<string, mixed>  $inputs
     */
    public function __construct(
        public ReadinessCeiling $ceiling,
        public bool $buildNudge,
        public array $reasons,
        public array $inputs,
    ) {
    }

    /**
     * @param  TrainingFormStatus|null  $formStatus  Current form status; null means unknown.
     * @param  int|null  $recoveryHours  Literal hours since any run, retained as context only.
     * @param  array<string, mixed>|null  $stressProfile  Actual recent activities, regardless of the planned session label.
     * @param  array{low: float, high: float}|null  $weeklyTrimpRange  Personal reference range, excluding the current window, when available.
     * @param  float|null  $aheadOfPlanPct  Actual km-to-date against prescribed km-to-date this week; null without a prescription.
     */
    public static function assess(
        ?TrainingFormStatus $formStatus,
        ?int $recoveryHours,
        bool $ranToday,
        ?float $monotony,
        ?float $volumeRampPct,
        string $fitnessTrend,
        ?array $stressProfile = null,
        ?float $weeklyTrimp = null,
        ?array $weeklyTrimpRange = null,
        bool $formConflict = false,
        ?float $aheadOfPlanPct = null,
    ): self {
        $stressProfile ??= [
            'sessions' => [],
            'last_demanding_hours' => null,
            'demanding_within_24h' => 0,
            'demanding_within_48h' => 0,
        ];
        $recentDemanding = (int) ($stressProfile['demanding_within_24h'] ?? 0) > 0;
        $closelySpacedDemanding = (int) ($stressProfile['demanding_within_48h'] ?? 0) > 1;
        $aheadOfPlan = $aheadOfPlanPct !== null && $aheadOfPlanPct > 15.0;
        $loadAboveTypical = $weeklyTrimp !== null
            && $weeklyTrimpRange !== null
            && $weeklyTrimp > $weeklyTrimpRange['high']
            && ($aheadOfPlanPct === null || $aheadOfPlanPct > 0.0);

        $ceiling = ReadinessCeiling::QualityOk;
        $reasons = [];

        if ($ranToday) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::EasyOnly);
            $reasons[] = 'already_ran_today';
        }
        if ($aheadOfPlan) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
            $reasons[] = 'running_ahead_of_plan';
        }
        if ($loadAboveTypical) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
            $reasons[] = 'weekly_load_above_personal_range';
        }
        if ($recentDemanding) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
            $reasons[] = 'demanding_session_within_24h';
        }
        if ($closelySpacedDemanding) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
            $reasons[] = 'closely_spaced_demanding_sessions';
        }
        if ($formConflict) {
            $reasons[] = 'conflicting_form_signals';
        }
        $buildNudge = $formStatus === TrainingFormStatus::Fresh
            && $fitnessTrend !== 'up'
            && ! $ranToday
            && $reasons === [];

        return new self(
            ceiling: $ceiling,
            buildNudge: $buildNudge,
            reasons: array_values(array_unique($reasons)),
            inputs: [
                'form_status' => $formStatus?->value,
                'recovery_hours_since_any_run' => $recoveryHours,
                'ran_today' => $ranToday,
                'monotony' => $monotony,
                'volume_ramp_pct' => $volumeRampPct,
                'fitness_trend' => $fitnessTrend,
                'recent_training_stress' => $stressProfile,
                'weekly_trimp' => $weeklyTrimp,
                'weekly_trimp_reference' => $weeklyTrimpRange,
                'ahead_of_plan_pct' => $aheadOfPlanPct,
                'form_signals_conflict' => $formConflict,
            ],
        );
    }

    /** @return array{ceiling: string, reasons: list<string>, inputs: array<string, mixed>} */
    public function toArray(): array
    {
        return [
            'ceiling' => $this->ceiling->value,
            'reasons' => $this->reasons,
            'inputs' => $this->inputs,
        ];
    }

}
