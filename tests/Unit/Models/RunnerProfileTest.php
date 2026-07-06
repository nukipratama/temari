<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

it('belongs to a user', function (): void {
    $user = User::factory()->create();
    $profile = RunnerProfile::factory()->for($user)->create();

    expect($profile->user)->toBeInstanceOf(User::class)
        ->and($profile->user->is($user))->toBeTrue();
});

it('casts hr_zones to an array', function (): void {
    $profile = RunnerProfile::factory()->make(['user_id' => 1]);

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

it('forgets the shared hr-zones-changed-at cache prop on every save', function (): void {
    $profile = RunnerProfile::factory()->create();
    $cacheKey = "hr-zones-changed-at:{$profile->user_id}";
    Cache::put($cacheKey, 'stale-value');

    $profile->update(['optimal_cadence_spm' => 175]);

    expect(Cache::has($cacheKey))->toBeFalse();
});
