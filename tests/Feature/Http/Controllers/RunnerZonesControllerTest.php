<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\Strava\ZoneFetcher;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
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

it('requires authentication for the update', function (): void {
    $this->patch('/settings/zones', validZonesPayload())->assertRedirect('/login');
});

it('creates a runner_profiles row and bumps hr_zones_changed_at', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/zones', validZonesPayload())
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
        ->patch('/settings/zones', validZonesPayload())
        ->assertRedirect();

    expect(RunnerProfile::query()->where('user_id', $user->id)->value('source'))->toBe('manual');
});

it('updates the existing row in place rather than creating a second one', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 170]);

    $this->actingAs($user)
        ->patch('/settings/zones', validZonesPayload())
        ->assertRedirect();

    expect(RunnerProfile::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(RunnerProfile::query()->where('user_id', $user->id)->value('max_hr'))->toBe(190);
});

it('rejects an invalid submission and persists nothing', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/zones', validZonesPayload(['max_hr' => 90]))
        ->assertSessionHasErrors('max_hr');

    expect(RunnerProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

it('does not dispatch any recompute job on update (forward-only design)', function (): void {
    Queue::fake();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->patch('/settings/zones', validZonesPayload())
        ->assertRedirect();

    Queue::assertNothingPushed();
});

it('resets to default by deleting the runner profile', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['source' => 'manual']);

    $this->actingAs($user)
        ->delete('/settings/zones')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(RunnerProfile::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $this->actingAs($user)->get('/settings')
        ->assertInertia(fn (Assert $page) => $page->where('hrZones.source', 'default'));
});

it('re-syncs from Strava inline and flips the source to strava for a scoped user', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);
    RunnerProfile::factory()->for($user)->create(['source' => 'manual', 'max_hr' => 200]);

    $zones = [
        'Z1' => ['lo' => 100, 'hi' => 125],
        'Z2' => ['lo' => 125, 'hi' => 145],
        'Z3' => ['lo' => 145, 'hi' => 165],
        'Z4' => ['lo' => 165, 'hi' => 180],
        'Z5' => ['lo' => 180, 'hi' => 999],
    ];
    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldReceive('fetch')->once()->andReturn($zones);
    app()->instance(ZoneFetcher::class, $fetcher);

    $this->actingAs($user)
        ->post('/settings/zones/resync-strava')
        ->assertRedirect()
        ->assertSessionHas('success');

    expect(RunnerProfile::query()->where('user_id', $user->id)->value('source'))->toBe('strava');
});

it('forbids re-syncing without the profile:read_all scope, without touching Strava', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    app()->instance(ZoneFetcher::class, $fetcher);

    $this->actingAs($user)
        ->post('/settings/zones/resync-strava')
        ->assertForbidden();
});

it('says so instead of touching Strava while the kill-switch is off', function (): void {
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);
    RunnerProfile::factory()->for($user)->create(['source' => 'manual', 'max_hr' => 200]);

    $fetcher = Mockery::mock(ZoneFetcher::class);
    $fetcher->shouldNotReceive('fetch');
    app()->instance(ZoneFetcher::class, $fetcher);

    $this->actingAs($user)
        ->post('/settings/zones/resync-strava')
        ->assertRedirect()
        ->assertSessionMissing('success')
        ->assertSessionHas('info');

    expect(RunnerProfile::query()->where('user_id', $user->id)->value('source'))->toBe('manual');
});
