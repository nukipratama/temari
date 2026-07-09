<?php

declare(strict_types=1);

use App\Services\Run\Metrics\Readiness;
use App\Services\Run\Metrics\ReadinessCeiling;

/**
 * assess($formStatus, $recoveryHours, $ranToday, $monotony, $volumeRampPct, $fitnessTrend)
 */

it('greenlights quality only when every signal lines up', function (): void {
    $r = Readiness::assess('fresh', 60, false, 1.2, 5.0, 'naik');

    expect($r->ceiling)->toBe(ReadinessCeiling::QualityOk)
        ->and($r->buildNudge)->toBeFalse(); // already ramping, no nudge needed
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
    'overreaching forces rest even while ramping' => ['overreaching', 72, false, 1.0, 0.0, 'naik', ReadinessCeiling::Rest],
    'already ran today caps at easy even if fresh + rested' => ['fresh', 60, true, 1.0, 0.0, 'naik', ReadinessCeiling::EasyOnly],
    'fatigued caps at easy' => ['fatigued', 60, false, 1.0, 0.0, 'plateau', ReadinessCeiling::EasyOnly],
    'high monotony caps at easy even when fresh' => ['fresh', 60, false, 2.5, 0.0, 'naik', ReadinessCeiling::EasyOnly],
    'under-24h recovery caps at easy' => ['fresh', 12, false, 1.0, 0.0, 'naik', ReadinessCeiling::EasyOnly],
    // Softer caps -> moderate, quality withheld.
    'volume jump over 15% withholds quality' => ['fresh', 60, false, 1.0, 20.0, 'naik', ReadinessCeiling::ModerateOk],
    '24-48h recovery withholds quality' => ['fresh', 36, false, 1.0, 0.0, 'naik', ReadinessCeiling::ModerateOk],
    'monotony 1.8-2.0 withholds quality' => ['optimal', 60, false, 1.9, 0.0, 'plateau', ReadinessCeiling::ModerateOk],
    'unknown form + recovery falls back to moderate' => [null, null, false, null, null, 'plateau', ReadinessCeiling::ModerateOk],
]);

it('nudges a fresh but detraining runner to build, within the ceiling', function (): void {
    // Fresh, fully recovered but only 36h, flat/declining fitness -> moderate cap + build nudge.
    $r = Readiness::assess('fresh', 36, false, 1.0, 0.0, 'turun');

    expect($r->ceiling)->toBe(ReadinessCeiling::ModerateOk)
        ->and($r->buildNudge)->toBeTrue();
});

it('never lets a build nudge override a red flag', function (): void {
    // Fresh + detraining would nudge, but high monotony is a red flag.
    $r = Readiness::assess('fresh', 60, false, 2.5, 0.0, 'turun');

    expect($r->ceiling)->toBe(ReadinessCeiling::EasyOnly)
        ->and($r->buildNudge)->toBeFalse();
});

it('does not nudge a runner who already ramping or ran today', function (): void {
    expect(Readiness::assess('fresh', 60, false, 1.0, 0.0, 'naik')->buildNudge)->toBeFalse()
        ->and(Readiness::assess('fresh', 60, true, 1.0, 0.0, 'turun')->buildNudge)->toBeFalse();
});
