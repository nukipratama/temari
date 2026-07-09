<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function validZonesPayload(array $overrides = []): array
{
    return [
        'max_hr' => 190,
        'resting_hr' => 50,
        'zones' => [
            ['lo' => 120, 'hi' => 140],
            ['lo' => 140, 'hi' => 158],
            ['lo' => 158, 'hi' => 172],
            ['lo' => 172, 'hi' => 184],
            ['lo' => 184, 'hi' => 999],
        ],
        ...$overrides,
    ];
}

it('requires authentication for the index', function (): void {
    $this->get('/pengaturan/zona')->assertRedirect('/login');
});

it('requires authentication for the update', function (): void {
    $this->patch('/pengaturan/zona', validZonesPayload())->assertRedirect('/login');
});

it('renders the page with the config-fallback profile for a fresh user', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/pengaturan/zona')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Pengaturan/ZonaHR')
            ->where('hasCustomProfile', false)
            ->where('profile.max_hr', 180)
            ->where('profile.resting_hr', 55)
            ->where('profile.hr_zones.Z1.lo', 116));
});

it('renders the page with the stored custom profile', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 195]);

    $this->actingAs($user)->get('/pengaturan/zona')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasCustomProfile', true)
            ->where('profile.max_hr', 195));
});

it('creates a runner_profiles row and bumps hr_zones_changed_at', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/pengaturan/zona', validZonesPayload())
        ->assertRedirect()
        ->assertSessionHas('success');

    $profile = RunnerProfile::query()->where('user_id', $user->id)->firstOrFail();

    expect($profile->max_hr)->toBe(190)
        ->and($profile->resting_hr)->toBe(50)
        ->and($profile->hr_zones['Z3'])->toEqual(['lo' => 158, 'hi' => 172])
        ->and($profile->hr_zones_changed_at)->not->toBeNull();
});

it('marks the profile source as manual on save', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/pengaturan/zona', validZonesPayload())
        ->assertRedirect();

    expect(RunnerProfile::query()->where('user_id', $user->id)->value('source'))->toBe('manual');
});

it('updates the existing row in place rather than creating a second one', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 170]);

    $this->actingAs($user)
        ->patch('/pengaturan/zona', validZonesPayload())
        ->assertRedirect();

    expect(RunnerProfile::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(RunnerProfile::query()->where('user_id', $user->id)->value('max_hr'))->toBe(190);
});

it('rejects an invalid submission and persists nothing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/pengaturan/zona', validZonesPayload(['max_hr' => 90]))
        ->assertSessionHasErrors('max_hr');

    expect(RunnerProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not dispatch any recompute job on update (forward-only design)', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/pengaturan/zona', validZonesPayload())
        ->assertRedirect();

    Queue::assertNothingPushed();
});
