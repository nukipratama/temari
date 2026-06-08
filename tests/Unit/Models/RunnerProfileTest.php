<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('returns config runner defaults from hrProfile when the user has no profile', function (): void {
    $user = User::factory()->create();

    expect($user->hrProfile())->toBe([
        'max_hr' => (int) config('runner.max_hr'),
        'resting_hr' => (int) config('runner.resting_hr'),
        'hr_zones' => config('runner.hr_zones'),
        'optimal_cadence_spm' => (int) config('runner.optimal_cadence_spm'),
    ]);
});

it('returns the stored profile row values from hrProfile when a profile exists', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create([
        'max_hr' => 190,
        'resting_hr' => 48,
        'hr_zones' => [
            'Z1' => ['lo' => 120, 'hi' => 140],
            'Z2' => ['lo' => 140, 'hi' => 160],
            'Z3' => ['lo' => 160, 'hi' => 172],
            'Z4' => ['lo' => 172, 'hi' => 182],
            'Z5' => ['lo' => 182, 'hi' => 999],
        ],
        'optimal_cadence_spm' => 178,
    ]);

    expect($user->refresh()->hrProfile())->toEqual([
        'max_hr' => 190,
        'resting_hr' => 48,
        'hr_zones' => [
            'Z1' => ['lo' => 120, 'hi' => 140],
            'Z2' => ['lo' => 140, 'hi' => 160],
            'Z3' => ['lo' => 160, 'hi' => 172],
            'Z4' => ['lo' => 172, 'hi' => 182],
            'Z5' => ['lo' => 182, 'hi' => 999],
        ],
        'optimal_cadence_spm' => 178,
    ]);
});

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $profile = RunnerProfile::factory()->for($user)->create();

    expect($profile->user)->toBeInstanceOf(User::class)
        ->and($profile->user->is($user))->toBeTrue();
});

it('casts hr_zones to an array', function (): void {
    $profile = RunnerProfile::factory()->create();

    expect($profile->hr_zones)->toBeArray()
        ->and($profile->hr_zones['Z1'])->toBe(['lo' => 116, 'hi' => 138]);
});

it('bumps hr_zones_changed_at when max_hr changes', function (): void {
    $profile = RunnerProfile::factory()->create(['hr_zones_changed_at' => null]);

    expect($profile->hr_zones_changed_at)->not->toBeNull();

    $profile->update(['hr_zones_changed_at' => null, 'max_hr' => 185]);

    expect($profile->fresh()->hr_zones_changed_at)->toBeInstanceOf(Carbon::class);
});

it('bumps hr_zones_changed_at when hr_zones changes', function (): void {
    $profile = RunnerProfile::factory()->create();
    $profile->forceFill(['hr_zones_changed_at' => null])->saveQuietly();

    $profile->update(['hr_zones' => [
        'Z1' => ['lo' => 110, 'hi' => 130],
        'Z2' => ['lo' => 130, 'hi' => 150],
        'Z3' => ['lo' => 150, 'hi' => 165],
        'Z4' => ['lo' => 165, 'hi' => 175],
        'Z5' => ['lo' => 175, 'hi' => 999],
    ]]);

    expect($profile->fresh()->hr_zones_changed_at)->toBeInstanceOf(Carbon::class);
});

it('leaves hr_zones_changed_at untouched when only optimal_cadence_spm changes', function (): void {
    $profile = RunnerProfile::factory()->create();
    $profile->forceFill(['hr_zones_changed_at' => null])->saveQuietly();

    expect($profile->fresh()->hr_zones_changed_at)->toBeNull();

    $profile->update(['optimal_cadence_spm' => 175]);

    expect($profile->fresh()->hr_zones_changed_at)->toBeNull()
        ->and($profile->fresh()->optimal_cadence_spm)->toBe(175);
});
