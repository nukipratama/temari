<?php

declare(strict_types=1);

use App\Enums\ExperienceLevel;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 12:00:00');
    $this->baseline = new TrainingBaseline();
});
afterEach(fn () => Carbon::setTestNow());

it('falls back to the floor of 3 sessions/week and a default volume with no history', function (): void {
    $user = User::factory()->create();

    $result = $this->baseline->forUser($user, Carbon::today());

    expect($result['sessions_per_week'])->toBe(3)
        ->and($result['weekly_volume_km'])->toBe(15.0)
        ->and($result['long_run_km'])->toBeGreaterThan(0.0);
});

it('clamps sessions_per_week to the trailing 4-week average, floored at 3 and capped at 6', function (): void {
    $user = User::factory()->create();
    foreach ([7, 7, 7, 7] as $i => $runs) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => $runs,
            'distance_km' => 40.0,
        ]);
    }

    expect($this->baseline->forUser($user, Carbon::today())['sessions_per_week'])->toBe(6);
});

it('averages weekly_volume_km over the trailing 4 completed weeks', function (): void {
    $user = User::factory()->create();
    foreach ([20.0, 24.0, 28.0, 32.0] as $i => $km) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 4,
            'distance_km' => $km,
        ]);
    }

    expect($this->baseline->forUser($user, Carbon::today())['weekly_volume_km'])->toBe(26.0);
});

it('uses the longest run in the trailing 28 days as the long-run baseline', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 18_000,
        'start_date_local' => Carbon::today()->subDays(5),
    ]);
    $other = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($other)->create([
        'distance' => 8_000,
        'start_date_local' => Carbon::today()->subDays(2),
    ]);

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBe(18.0);
});

it('ignores runs older than 28 days for the long-run baseline', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 30_000,
        'start_date_local' => Carbon::today()->subDays(40),
    ]);

    expect($this->baseline->forUser($user, Carbon::today())['long_run_km'])->toBeLessThan(30.0);
});

it('an explicit sessions_per_week preference overrides the behavioral average', function (): void {
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'runs' => 6,
        'distance_km' => 40.0,
    ]);
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 2]);

    expect($this->baseline->forUser($user, Carbon::today())['sessions_per_week'])->toBe(2);
});

it('an explicit sessions_per_week preference bypasses the behavioral floor of 3 with zero history', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create(['sessions_per_week' => 2]);

    expect($this->baseline->forUser($user, Carbon::today())['sessions_per_week'])->toBe(2);
});

it('seeds cold-start defaults from experience_level with zero history and no explicit sessions preference', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create([
        'experience_level' => ExperienceLevel::NewToRunning,
        'sessions_per_week' => null,
    ]);

    $result = $this->baseline->forUser($user, Carbon::today());

    expect($result['sessions_per_week'])->toBe(3)
        ->and($result['weekly_volume_km'])->toBe(12.0);
});

it('real logged behavior wins over an experience_level seed once any history exists', function (): void {
    $user = User::factory()->create();
    TrainingPreference::factory()->for($user)->create([
        'experience_level' => ExperienceLevel::Experienced,
        'sessions_per_week' => null,
    ]);
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'runs' => 4,
        'distance_km' => 22.0,
    ]);

    $result = $this->baseline->forUser($user, Carbon::today());

    expect($result['sessions_per_week'])->toBe(4)
        ->and($result['weekly_volume_km'])->toBe(22.0);
});
