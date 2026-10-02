<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use App\Services\Run\Metrics\RecentTrainingStress;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

it('excludes future starts and gives no recovery credit before a session ends', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    recentStressRun($user, '2026-10-01 09:00', ['elapsed_time' => 7200]);
    recentStressRun($user, '2026-10-01 07:00', ['elapsed_time' => 7200]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'])->toHaveCount(1)
        ->and($profile['sessions'][0]['hours_since_end'])->toBe(0)
        ->and($profile['last_demanding_hours'])->toBe(0);
});

function recentStressRun(User $user, string $start, array $attributes = []): ActivityDetail
{
    $activity = Activity::factory()->for($user)->analyzed()->create();

    return ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($start),
        ...$attributes,
    ]);
}

it('finds actual demanding work from heart-rate zones and elapsed duration without reading the plan label', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();

    recentStressRun($user, '2026-09-30 07:00', [
        'elapsed_time' => 3600,
        'distance' => 10_000,
        'trimp_edwards' => 130.0,
        'stream_summary' => [
            'time_in_zone_min' => ['Z1' => 10, 'Z2' => 20, 'Z3' => 15, 'Z4' => 12, 'Z5' => 1],
            'laps' => [['lap' => 1]],
            'gap_pace' => '5:50',
        ],
    ]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'])->toHaveCount(1)
        ->and($profile['sessions'][0])->toMatchArray([
            'date' => '2026-09-30',
            'hours_ago' => 25,
            'hours_since_end' => 24,
            'distance_km' => 10.0,
            'duration_minutes' => 60,
            'trimp' => 130.0,
            'threshold_minutes' => 13.0,
            'has_hr_evidence' => true,
            'has_laps' => true,
            'has_gap' => true,
            'demanding' => true,
            'reasons' => ['sustained_threshold_effort'],
        ])
        ->and($profile['last_demanding_hours'])->toBe(24)
        ->and($profile['demanding_within_24h'])->toBe(0)
        ->and($profile['demanding_within_48h'])->toBe(1);
});

it('counts demanding recovery from the end of a known-duration session', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();

    recentStressRun($user, '2026-09-30 07:00', [
        'elapsed_time' => 7200,
        'distance' => 20_000,
        'stream_summary' => null,
    ]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'][0])->toMatchArray([
        'hours_ago' => 25,
        'hours_since_end' => 23,
        'demanding' => true,
    ])
        ->and($profile['last_demanding_hours'])->toBe(23)
        ->and($profile['demanding_within_24h'])->toBe(1);
});

it('recognises sustained threshold laps without heart-rate data', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200,
        'set_at' => '2026-09-01',
    ]);
    $thresholdPace = app(TrainingPaceCalculator::class)->fromVdotResult(
        app(VdotEstimator::class)->estimate($user, $asOf),
    )['threshold'];
    $pace = sprintf('%d:%02d', intdiv($thresholdPace, 60), $thresholdPace % 60);

    recentStressRun($user, '2026-09-30 07:00', [
        'elapsed_time' => 30 * 60,
        'distance' => 6000,
        'has_heartrate' => false,
        'average_heartrate' => null,
        'stream_summary' => [
            'laps' => [
                ['elapsed_sec' => 300, 'distance_m' => (int) round(300_000 / $thresholdPace), 'pace' => $pace],
                ['elapsed_sec' => 300, 'distance_m' => (int) round(300_000 / $thresholdPace), 'pace' => $pace],
                ['elapsed_sec' => 300, 'distance_m' => (int) round(300_000 / $thresholdPace), 'pace' => $pace],
            ],
        ],
    ]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'][0]['has_hr_evidence'])->toBeFalse()
        ->and($profile['sessions'][0]['demanding'])->toBeTrue()
        ->and($profile['sessions'][0]['reasons'])->toContain('sustained_threshold_lap_effort');
});

it('recognises threshold grade-adjusted pace when heart-rate data is absent', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1200,
        'set_at' => '2026-09-01',
    ]);
    $thresholdPace = app(TrainingPaceCalculator::class)->fromVdotResult(
        app(VdotEstimator::class)->estimate($user, $asOf),
    )['threshold'];
    $gapPace = sprintf('%d:%02d', intdiv($thresholdPace, 60), $thresholdPace % 60);

    recentStressRun($user, '2026-09-30 07:00', [
        'elapsed_time' => 30 * 60,
        'distance' => 6000,
        'has_heartrate' => false,
        'average_heartrate' => null,
        'stream_summary' => ['gap_pace' => $gapPace],
    ]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'][0]['demanding'])->toBeTrue()
        ->and($profile['sessions'][0]['reasons'])->toContain('sustained_threshold_gap_effort');
});

it('uses available personal duration history to recognise a relative long run', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();

    recentStressRun($user, '2026-09-28 07:00', ['elapsed_time' => 1800, 'distance' => 5000]);
    recentStressRun($user, '2026-09-29 07:00', ['elapsed_time' => 1800, 'distance' => 5000]);
    recentStressRun($user, '2026-09-30 07:00', ['elapsed_time' => 3600, 'distance' => 10_000]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'][2]['demanding'])->toBeTrue()
        ->and($profile['sessions'][2]['reasons'])->toContain('relative_long_duration');
});

it('keeps unusable pace data unknown when heart-rate, usable laps, and grade-adjusted pace are absent', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();

    recentStressRun($user, '2026-09-30 07:00', [
        'elapsed_time' => 1800,
        'distance' => 6000,
        'has_heartrate' => false,
        'average_heartrate' => null,
        'stream_summary' => ['laps' => [['lap' => 1]], 'gap_pace' => 'fast'],
    ]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'][0]['demanding'])->toBeFalse()
        ->and($profile['sessions'][0]['reasons'])->toBe([]);
});

it('counts a long run from duration while leaving short runs with missing effort data unknown', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();

    recentStressRun($user, '2026-09-29 06:00', [
        'elapsed_time' => 6000,
        'distance' => 18_000,
        'has_heartrate' => false,
        'average_heartrate' => null,
        'trimp_edwards' => null,
        'stream_summary' => null,
    ]);
    recentStressRun($user, '2026-10-01 06:00', [
        'elapsed_time' => 2400,
        'distance' => 5000,
        'has_heartrate' => false,
        'average_heartrate' => null,
        'trimp_edwards' => null,
        'stream_summary' => null,
    ]);
    recentStressRun($user, '2026-09-23 06:00', [
        'elapsed_time' => 10_000,
        'distance' => 30_000,
    ]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'])->toHaveCount(2)
        ->and($profile['sessions'][0]['demanding'])->toBeTrue()
        ->and($profile['sessions'][0]['reasons'])->toBe(['long_duration'])
        ->and($profile['sessions'][1])->toMatchArray([
            'has_hr_evidence' => false,
            'has_laps' => false,
            'has_gap' => false,
            'demanding' => false,
            'reasons' => [],
        ])
        ->and($profile['last_demanding_hours'])->toBe(48);
});

it('does not count ordinary easy runs as demanding or include runs outside the seven-day window', function (): void {
    $asOf = Carbon::parse('2026-10-01 08:00');
    Carbon::setTestNow($asOf);
    $user = User::factory()->create();

    recentStressRun($user, '2026-09-30 07:00', [
        'elapsed_time' => 3600,
        'trimp_edwards' => 75.0,
        'stream_summary' => ['time_in_zone_min' => ['Z1' => 10, 'Z2' => 50]],
    ]);
    recentStressRun($user, '2026-09-23 06:00', [
        'elapsed_time' => 7200,
        'stream_summary' => ['time_in_zone_min' => ['Z4' => 20]],
    ]);

    $profile = app(RecentTrainingStress::class)->forUser($user, $asOf);

    expect($profile['sessions'])->toHaveCount(1)
        ->and($profile['sessions'][0]['demanding'])->toBeFalse()
        ->and($profile['last_demanding_hours'])->toBeNull();
});
