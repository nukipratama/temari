<?php

declare(strict_types=1);

use App\Services\Run\Metrics\Readiness;
use App\Services\Run\Metrics\ReadinessCeiling;
use App\Services\Run\Metrics\TrainingFormStatus;

/**
 * assess($formStatus, $recoveryHours, $ranToday, $monotony, $volumeRampPct, $fitnessTrend)
 */

it('greenlights quality only when every signal lines up', function (): void {
    $r = Readiness::assess(TrainingFormStatus::Fresh, 60, false, 1.2, 5.0, 'up');

    expect($r->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($r->buildNudge)->toBeFalse(); // already ramping, no nudge needed
});

it('does not withhold quality from an optimal runner solely because 45 hours have passed', function (): void {
    $readiness = Readiness::assess(TrainingFormStatus::Optimal, 45, false, 1.0, 0.0, 'plateau');

    expect($readiness->ceiling)->toBe(ReadinessCeiling::QualityOk);
});

it('caps at the most restrictive guardrail', function (
    ?TrainingFormStatus $form,
    ?int $recovery,
    bool $ranToday,
    ?float $monotony,
    ?float $ramp,
    string $trend,
    ReadinessCeiling $expected,
): void {
    expect(Readiness::assess($form, $recovery, $ranToday, $monotony, $ramp, $trend)->ceiling)
        ->toBe($expected);
})->with([
    // Hard red flags -> easy/rest, regardless of any positive signal.
    'overreaching form alone is not a concern' => [TrainingFormStatus::Overreaching, 72, false, 1.0, 0.0, 'up', ReadinessCeiling::QualityOk],
    'already ran today caps at easy even if fresh + rested' => [TrainingFormStatus::Fresh, 60, true, 1.0, 0.0, 'up', ReadinessCeiling::EasyOnly],
    'fatigued form alone is not a concern' => [TrainingFormStatus::Fatigued, 60, false, 1.0, 0.0, 'plateau', ReadinessCeiling::QualityOk],
    'high monotony is descriptive only' => [TrainingFormStatus::Fresh, 60, false, 2.5, 0.0, 'up', ReadinessCeiling::QualityOk],
    // Softer caps -> moderate, quality withheld.
    'a week-over-week jump alone does not withhold quality' => [TrainingFormStatus::Fresh, 60, false, 1.0, 65.0, 'up', ReadinessCeiling::QualityOk],
    'ordinary run recency does not withhold quality' => [TrainingFormStatus::Fresh, 12, false, 1.0, 0.0, 'up', ReadinessCeiling::QualityOk],
    'borderline monotony alone remains uncertain' => [TrainingFormStatus::Optimal, 60, false, 1.9, 0.0, 'plateau', ReadinessCeiling::QualityOk],
    'unknown form and recency remain unknown' => [null, null, false, null, null, 'plateau', ReadinessCeiling::QualityOk],
]);

it('nudges a fresh but detraining runner to build, within the ceiling', function (): void {
    // Run recency stays visible but does not decide readiness.
    $r = Readiness::assess(TrainingFormStatus::Fresh, 36, false, 1.0, 0.0, 'down');

    expect($r->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($r->buildNudge)->toBeTrue();
});

it('never lets a build nudge override a red flag', function (): void {
    $r = Readiness::assess(TrainingFormStatus::Fresh, 60, false, 1.0, 0.0, 'down', aheadOfPlanPct: 22.0);

    expect($r->ceiling)->toBe(ReadinessCeiling::ModerateOk)
        ->and($r->buildNudge)->toBeFalse();
});

it('does not nudge a runner who already ramping or ran today', function (): void {
    expect(Readiness::assess(TrainingFormStatus::Fresh, 60, false, 1.0, 0.0, 'up')->buildNudge)->toBeFalse()
        ->and(Readiness::assess(TrainingFormStatus::Fresh, 60, true, 1.0, 0.0, 'down')->buildNudge)->toBeFalse();
});

/**
 * An athlete whose runs carry no heart rate has no CTL or ATL, so form_status
 * is null for them on every single day. Treating that as a fatigue signal
 * withheld every quality session the plan ever prescribed them.
 */
it('does not withhold quality for an athlete whose form is simply unknown', function (): void {
    $readiness = Readiness::assess(
        formStatus: null,
        recoveryHours: 72,
        ranToday: false,
        monotony: null,
        volumeRampPct: null,
        fitnessTrend: 'flat',
    );

    expect($readiness->ceiling)->toBe(ReadinessCeiling::QualityOk);
});

it('withholds quality after closely spaced actual demanding sessions, not after an easy run', function (): void {
    $readiness = Readiness::assess(
        formStatus: TrainingFormStatus::Optimal,
        recoveryHours: 8,
        ranToday: false,
        monotony: 1.2,
        volumeRampPct: 0.0,
        fitnessTrend: 'up',
        stressProfile: [
            'sessions' => [],
            'last_demanding_hours' => 20,
            'demanding_within_24h' => 1,
            'demanding_within_48h' => 1,
        ],
    );

    expect($readiness->ceiling)->toBe(ReadinessCeiling::ModerateOk)
        ->and($readiness->reasons)->toContain('demanding_session_within_24h');
});

it('lets optimal form clear quality at 45 hours after the last demanding session', function (): void {
    $readiness = Readiness::assess(
        formStatus: TrainingFormStatus::Optimal,
        recoveryHours: 45,
        ranToday: false,
        monotony: 1.2,
        volumeRampPct: 0.0,
        fitnessTrend: 'up',
        stressProfile: [
            'sessions' => [],
            'last_demanding_hours' => 45,
            'demanding_within_24h' => 0,
            'demanding_within_48h' => 1,
        ],
    );

    expect($readiness->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($readiness->reasons)->toBe([]);
});

it('records conflicting form signals without hiding them or withholding quality', function (): void {
    $readiness = Readiness::assess(
        formStatus: TrainingFormStatus::Optimal,
        recoveryHours: 45,
        ranToday: false,
        monotony: 1.0,
        volumeRampPct: 0.0,
        fitnessTrend: 'up',
        formConflict: true,
    );

    expect($readiness->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($readiness->reasons)->toBe(['conflicting_form_signals'])
        ->and($readiness->inputs['form_signals_conflict'])->toBeTrue();
});

it('never reaches rest from load evidence alone', function (): void {
    expect(Readiness::assess(TrainingFormStatus::Overreaching, 10, false, 2.5, 0.0, 'up', weeklyTrimp: 900.0, weeklyTrimpRange: ['low' => 300.0, 'high' => 400.0], aheadOfPlanPct: 60.0)->ceiling)
        ->toBe(ReadinessCeiling::ModerateOk);
});

it('withholds quality only when actual km-to-date runs well ahead of the prescription', function (?float $aheadOfPlanPct, ReadinessCeiling $expected): void {
    $readiness = Readiness::assess(TrainingFormStatus::Optimal, 60, false, 1.0, 65.0, 'plateau', aheadOfPlanPct: $aheadOfPlanPct);

    expect($readiness->ceiling)->toBe($expected)
        ->and($readiness->inputs['ahead_of_plan_pct'])->toBe($aheadOfPlanPct);
})->with([
    'on plan after a deload week' => [0.0, ReadinessCeiling::QualityOk],
    'slightly ahead' => [15.0, ReadinessCeiling::QualityOk],
    'well ahead' => [15.1, ReadinessCeiling::ModerateOk],
    'no prescription to compare' => [null, ReadinessCeiling::QualityOk],
]);

it('reads the personal range as a concern only when the athlete is also ahead of the prescription', function (?float $aheadOfPlanPct, ReadinessCeiling $expected): void {
    $readiness = Readiness::assess(TrainingFormStatus::Optimal, 60, false, 1.0, 0.0, 'plateau', weeklyTrimp: 700.0, weeklyTrimpRange: ['low' => 500.0, 'high' => 592.0], aheadOfPlanPct: $aheadOfPlanPct);

    expect($readiness->ceiling)->toBe($expected);
})->with([
    'matches the prescription' => [0.0, ReadinessCeiling::QualityOk],
    'ahead of the prescription' => [5.0, ReadinessCeiling::ModerateOk],
    'no prescription' => [null, ReadinessCeiling::ModerateOk],
]);

it('does not read a steady week exactly at its reference as above range', function (): void {
    expect(Readiness::assess(TrainingFormStatus::Optimal, 60, false, 1.0, 0.0, 'plateau', weeklyTrimp: 592.0, weeklyTrimpRange: ['low' => 592.0, 'high' => 592.0])->ceiling)
        ->toBe(ReadinessCeiling::QualityOk);
});

it('never counts monotony as a concern', function (): void {
    $readiness = Readiness::assess(TrainingFormStatus::Optimal, 60, false, 3.5, 0.0, 'plateau');

    expect($readiness->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($readiness->reasons)->toBe([])
        ->and($readiness->inputs['monotony'])->toBe(3.5);
});
