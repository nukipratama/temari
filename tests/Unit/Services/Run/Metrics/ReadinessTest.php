<?php

declare(strict_types=1);

use App\Services\Run\Metrics\Readiness;
use App\Services\Run\Metrics\ReadinessCeiling;

/**
 * assess($formStatus, $recoveryHours, $ranToday, $monotony, $volumeRampPct, $fitnessTrend)
 */

it('greenlights quality only when every signal lines up', function (): void {
    $r = Readiness::assess('fresh', 60, false, 1.2, 5.0, 'up');

    expect($r->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($r->buildNudge)->toBeFalse(); // already ramping, no nudge needed
});

it('does not withhold quality from an optimal runner solely because 45 hours have passed', function (): void {
    $readiness = Readiness::assess('optimal', 45, false, 1.0, 0.0, 'plateau');

    expect($readiness->ceiling)->toBe(ReadinessCeiling::QualityOk);
});

it('caps at the most restrictive guardrail', function (
    ?string $form,
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
    'overreaching forces rest even while ramping' => ['overreaching', 72, false, 1.0, 0.0, 'up', ReadinessCeiling::Rest],
    'already ran today caps at easy even if fresh + rested' => ['fresh', 60, true, 1.0, 0.0, 'up', ReadinessCeiling::EasyOnly],
    'fatigued form withholds quality' => ['fatigued', 60, false, 1.0, 0.0, 'plateau', ReadinessCeiling::ModerateOk],
    'high monotony withholds quality' => ['fresh', 60, false, 2.5, 0.0, 'up', ReadinessCeiling::ModerateOk],
    // Softer caps -> moderate, quality withheld.
    'volume jump over 15% withholds quality' => ['fresh', 60, false, 1.0, 20.0, 'up', ReadinessCeiling::ModerateOk],
    'ordinary run recency does not withhold quality' => ['fresh', 12, false, 1.0, 0.0, 'up', ReadinessCeiling::QualityOk],
    'borderline monotony alone remains uncertain' => ['optimal', 60, false, 1.9, 0.0, 'plateau', ReadinessCeiling::QualityOk],
    'unknown form and recency remain unknown' => [null, null, false, null, null, 'plateau', ReadinessCeiling::QualityOk],
]);

it('nudges a fresh but detraining runner to build, within the ceiling', function (): void {
    // Run recency stays visible but does not decide readiness.
    $r = Readiness::assess('fresh', 36, false, 1.0, 0.0, 'down');

    expect($r->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($r->buildNudge)->toBeTrue();
});

it('never lets a build nudge override a red flag', function (): void {
    // Fresh + detraining would nudge, but high monotony withholds quality.
    $r = Readiness::assess('fresh', 60, false, 2.5, 0.0, 'down');

    expect($r->ceiling)->toBe(ReadinessCeiling::ModerateOk)
        ->and($r->buildNudge)->toBeFalse();
});

it('does not nudge a runner who already ramping or ran today', function (): void {
    expect(Readiness::assess('fresh', 60, false, 1.0, 0.0, 'up')->buildNudge)->toBeFalse()
        ->and(Readiness::assess('fresh', 60, true, 1.0, 0.0, 'down')->buildNudge)->toBeFalse();
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

it('does not withhold quality for a form status it does not recognise', function (): void {
    $readiness = Readiness::assess(
        formStatus: 'something-new',
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
        formStatus: 'optimal',
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
        formStatus: 'optimal',
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

it('withholds hard advice for strong concerns while retaining the exact reason inputs', function (): void {
    $readiness = Readiness::assess(
        formStatus: 'optimal',
        recoveryHours: 45,
        ranToday: false,
        monotony: 1.0,
        volumeRampPct: 0.0,
        fitnessTrend: 'up',
        feedback: [
            'date' => '2026-10-01',
            'freshness' => 'current',
            'sleep_quality' => 'good',
            'fatigue' => 'none',
            'soreness' => 'none',
            'concerning_pain' => true,
            'illness' => false,
        ],
    );

    expect($readiness->ceiling)->toBe(ReadinessCeiling::Rest)
        ->and($readiness->reasons)->toContain('concerning_pain_reported')
        ->and($readiness->inputs['recovery_feedback']['concerning_pain'])->toBeTrue();
});

it('does not let a mild concern replace quality without supporting load evidence', function (): void {
    $readiness = Readiness::assess(
        formStatus: 'optimal',
        recoveryHours: 45,
        ranToday: false,
        monotony: 1.0,
        volumeRampPct: 0.0,
        fitnessTrend: 'up',
        feedback: [
            'date' => '2026-10-01',
            'freshness' => 'current',
            'sleep_quality' => 'good',
            'fatigue' => 'none',
            'soreness' => 'mild',
            'concerning_pain' => false,
            'illness' => false,
        ],
    );

    expect($readiness->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($readiness->reasons)->toContain('mild_feedback_without_load_support');
});

it('ignores stale feedback and records contradictory load signals without hiding them', function (): void {
    $readiness = Readiness::assess(
        formStatus: 'optimal',
        recoveryHours: 45,
        ranToday: false,
        monotony: 1.0,
        volumeRampPct: 0.0,
        fitnessTrend: 'up',
        feedback: [
            'date' => '2026-09-28',
            'freshness' => 'stale',
            'sleep_quality' => 'poor',
            'fatigue' => 'severe',
            'soreness' => 'severe',
            'concerning_pain' => true,
            'illness' => true,
        ],
        formConflict: true,
    );

    expect($readiness->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($readiness->reasons)->toContain('stale_recovery_feedback_not_applied')
        ->and($readiness->reasons)->toContain('conflicting_form_signals')
        ->and($readiness->inputs['recovery_feedback']['freshness'])->toBe('stale');
});

it('treats sleep, fatigue, and soreness as independent feedback dimensions', function (array $feedback): void {
    $readiness = Readiness::assess(
        formStatus: 'optimal',
        recoveryHours: 45,
        ranToday: false,
        monotony: 1.0,
        volumeRampPct: 0.0,
        fitnessTrend: 'up',
        feedback: ['freshness' => 'current', ...$feedback],
    );

    expect($readiness->reasons)->not->toContain('conflicting_recovery_feedback');
})->with([
    'good sleep with mild soreness' => [[
        'sleep_quality' => 'good',
        'fatigue' => 'none',
        'soreness' => 'mild',
    ]],
    'no fatigue with mild soreness' => [[
        'sleep_quality' => 'fair',
        'fatigue' => 'none',
        'soreness' => 'mild',
    ]],
]);

it('treats mild soreness alongside a sharp volume rise as moderate rather than a full hard-day replacement', function (): void {
    $readiness = Readiness::assess(
        formStatus: 'optimal',
        recoveryHours: 48,
        ranToday: false,
        monotony: 1.0,
        volumeRampPct: 22.0,
        fitnessTrend: 'up',
        feedback: [
            'freshness' => 'current',
            'sleep_quality' => 'good',
            'fatigue' => 'none',
            'soreness' => 'mild',
        ],
    );

    expect($readiness->ceiling)->toBe(ReadinessCeiling::ModerateOk)
        ->and($readiness->reasons)->toContain('volume_increased_sharply')
        ->and($readiness->reasons)->toContain('mild_fatigue_or_soreness_with_load_support');
});
