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
     * @param  string|null  $formStatus  Current form status; null means unknown.
     * @param  int|null  $recoveryHours  Literal hours since any run, retained as context only.
     * @param  array<string, mixed>|null  $stressProfile  Actual recent activities, regardless of the planned session label.
     * @param  array<string, mixed>|null  $feedback  Latest recovery feedback and its freshness.
     * @param  array{low: float, high: float}|null  $weeklyTrimpRange  Personal reference range, excluding the current window, when available.
     * @param  float|null  $aheadOfPlanPct  Actual km-to-date against prescribed km-to-date this week; null without a prescription.
     */
    public static function assess(
        ?string $formStatus,
        ?int $recoveryHours,
        bool $ranToday,
        ?float $monotony,
        ?float $volumeRampPct,
        string $fitnessTrend,
        ?array $stressProfile = null,
        ?array $feedback = null,
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
        $currentFeedback = ($feedback['freshness'] ?? null) === 'current' ? $feedback : null;
        $fatigue = $currentFeedback['fatigue'] ?? null;
        $soreness = $currentFeedback['soreness'] ?? null;
        $sleep = $currentFeedback['sleep_quality'] ?? null;
        $mildConcern = $fatigue === 'mild' || $soreness === 'mild' || $sleep === 'fair' || $sleep === 'poor';
        $moderateConcern = $fatigue === 'moderate' || $soreness === 'moderate';
        $recentDemanding = (int) ($stressProfile['demanding_within_24h'] ?? 0) > 0;
        $closelySpacedDemanding = (int) ($stressProfile['demanding_within_48h'] ?? 0) > 1;
        $aheadOfPlan = $aheadOfPlanPct !== null && $aheadOfPlanPct > 15.0;
        $loadAboveTypical = $weeklyTrimp !== null
            && $weeklyTrimpRange !== null
            && $weeklyTrimp > $weeklyTrimpRange['high']
            && ($aheadOfPlanPct === null || $aheadOfPlanPct > 0.0);
        $supportingLoad = in_array($formStatus, ['fatigued', 'overreaching'], true)
            || $aheadOfPlan
            || $recentDemanding
            || $closelySpacedDemanding
            || $loadAboveTypical;

        $ceiling = ReadinessCeiling::QualityOk;
        $reasons = [];

        if (($currentFeedback['concerning_pain'] ?? false) === true) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::Rest);
            $reasons[] = 'concerning_pain_reported';
        }
        if (($currentFeedback['illness'] ?? false) === true) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::Rest);
            $reasons[] = 'illness_reported';
        }
        if ($fatigue === 'severe' || $soreness === 'severe') {
            $ceiling = $ceiling->capTo(ReadinessCeiling::EasyOnly);
            $reasons[] = 'severe_fatigue_or_soreness_reported';
        }
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
        if ($moderateConcern) {
            $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
            $reasons[] = 'moderate_fatigue_or_soreness_reported';
        } elseif ($mildConcern) {
            $reasons[] = $supportingLoad
                ? (in_array($fatigue, ['mild', 'moderate'], true) || in_array($soreness, ['mild', 'moderate'], true)
                    ? 'mild_fatigue_or_soreness_with_load_support'
                    : "{$sleep}_sleep_with_load_support")
                : 'mild_feedback_without_load_support';
            if ($supportingLoad) {
                $ceiling = $ceiling->capTo(ReadinessCeiling::ModerateOk);
            }
        }
        if ($feedback !== null && ($feedback['freshness'] ?? null) === 'stale') {
            $reasons[] = 'stale_recovery_feedback_not_applied';
        }
        if ($formConflict) {
            $reasons[] = 'conflicting_form_signals';
        }
        $buildNudge = $formStatus === 'fresh'
            && $fitnessTrend !== 'up'
            && ! $ranToday
            && $reasons === [];

        return new self(
            ceiling: $ceiling,
            buildNudge: $buildNudge,
            reasons: array_values(array_unique($reasons)),
            inputs: [
                'form_status' => $formStatus,
                'recovery_hours_since_any_run' => $recoveryHours,
                'ran_today' => $ranToday,
                'monotony' => $monotony,
                'volume_ramp_pct' => $volumeRampPct,
                'fitness_trend' => $fitnessTrend,
                'recent_training_stress' => $stressProfile,
                'recovery_feedback' => $feedback,
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
