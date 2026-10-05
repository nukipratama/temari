<?php

declare(strict_types=1);

use App\Enums\PaceBand;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Services\Run\Plan\TimeTrial;

const TRIAL_ZONES = ['Z1' => ['lo' => 116, 'hi' => 138], 'Z2' => ['lo' => 138, 'hi' => 154], 'Z3' => ['lo' => 154, 'hi' => 168], 'Z4' => ['lo' => 168, 'hi' => 176], 'Z5' => ['lo' => 176, 'hi' => 999]];

it('runs a 10K for a half marathon or longer goal and a 5K otherwise', function (?float $raceDistanceM, int $trialM): void {
    expect(TimeTrial::distanceFor($raceDistanceM))->toBe($trialM);
})->with([
    'no race' => [null, 5_000],
    '5K' => [5_000.0, 5_000],
    '10K' => [10_000.0, 5_000],
    '15K' => [15_000.0, 10_000],
    'half' => [21_097.5, 10_000],
    'marathon' => [42_195.0, 10_000],
    'ultra' => [50_000.0, 10_000],
]);

it('prescribes the trial at the aim, as hard work carrying its own context', function (): void {
    $prescription = new TimeTrial(5_000, 1_500, retry: true)->prescription();

    expect($prescription->hardMinutes)->toBe(25)
        ->and($prescription->paceBand)->toBe(PaceBand::Interval)
        ->and($prescription->paceSecPerKm)->toBe(300)
        ->and($prescription->raceContext)->toBe(['kind' => 'time_trial', 'distance_m' => 5_000, 'aim_time_sec' => 1_500, 'retry' => 1])
        ->and(TimeTrial::isTrial($prescription->raceContext))->toBeTrue()
        ->and(new TimeTrial(10_000, 3_100)->prescription()->paceBand)->toBe(PaceBand::Threshold)
        ->and(TimeTrial::isTrial(['distance_m' => 10_000, 'goal_pace_sec_per_km' => 300, 'kind' => '10k', 'band' => 'on_track']))->toBeFalse()
        ->and(TimeTrial::isTrial(null))->toBeFalse();
});

it('sizes a trial day as the warmup plus the trial distance, and no other day', function (): void {
    $context = new TimeTrial(10_000, 3_100)->context();

    expect(TimeTrial::dayKm(SessionType::Tempo, $context))->toBe(12.0)
        ->and(TimeTrial::dayKm(SessionType::Interval, new TimeTrial(5_000, 1_500)->context()))->toBe(7.0)
        ->and(TimeTrial::dayKm(SessionType::Easy, $context))->toBeNull()
        ->and(TimeTrial::dayKm(SessionType::Tempo, null))->toBeNull();
});

it('reads a stored trial back only while it is still hard work on a quality day', function (array $attributes, bool $isTrial): void {
    $session = new PlannedSession()->forceFill([
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescribed_pace_sec_per_km' => 300,
        'prescription_race_context' => new TimeTrial(5_000, 1_500)->context(),
        ...$attributes,
    ]);

    expect(TimeTrial::of($session) !== null)->toBe($isTrial);
})->with([
    'as written' => [[], true],
    'kept easy' => [['prescribed_hard_minutes' => 0], false],
    'turned easy' => [['session_type' => SessionType::Easy], false],
    'another quality day' => [['prescription_race_context' => null], false],
]);

it('counts a run that reaches the trial distance and the aim, or the effort zone', function (float $meters, ?float $seconds, ?float $heartRate, ?string $basis): void {
    expect(new TimeTrial(5_000, 1_500)->gate($meters, $seconds, $heartRate, TRIAL_ZONES))->toBe($basis);
})->with([
    'on the aim' => [5_000.0, 1_500.0, null, 'pace'],
    'inside the slack' => [5_000.0, 1_575.0, null, 'pace'],
    'past the slack' => [5_000.0, 1_576.0, null, null],
    'a long run scaled to the trial' => [5_400.0, 1_700.0, null, 'pace'],
    'slow but in zone 4' => [5_000.0, 1_800.0, 168.0, 'heart_rate'],
    'slow below zone 4' => [5_000.0, 1_800.0, 167.0, null],
    'slow with no heart rate' => [5_000.0, 1_800.0, null, null],
    'too short however fast' => [4_400.0, 1_200.0, 180.0, null],
    'too long however fast' => [5_600.0, 1_500.0, 180.0, null],
    'no distance' => [0.0, 1_500.0, 180.0, null],
]);

it('reads a trial as skipped when excused, not run, or eased to easy or rest', function (array $attributes, bool $ran, bool $skipped): void {
    $session = new PlannedSession()->forceFill([
        'session_type' => SessionType::Interval,
        'prescribed_hard_minutes' => 25,
        'prescribed_pace_band' => PaceBand::Interval,
        'prescription_race_context' => new TimeTrial(5_000, 1_500)->context(),
        'skipped' => false,
        ...$attributes,
    ]);

    expect(TimeTrial::countsAsSkipped($session, $ran))->toBe($skipped);
})->with([
    'run as planned' => [[], true, false],
    'excused' => [['skipped' => true], true, true],
    'nothing run' => [[], false, true],
    'eased to easy' => [['clamped_km' => 6.0], true, true],
    'rested' => [['rest_clamped_at' => '2026-10-06 07:00:00'], true, true],
    'shown easy when run' => [['intent_evidence' => ['effective_type' => 'easy']], true, true],
]);
