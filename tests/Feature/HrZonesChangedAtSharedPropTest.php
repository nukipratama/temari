<?php

declare(strict_types=1);

use App\Models\RunnerProfile;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares null when the user has no runner profile', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('hrZonesChangedAt', null));
});

it('shares null when the profile has never changed its zones', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $profile = RunnerProfile::factory()->for($user)->create();
    $profile->forceFill(['hr_zones_changed_at' => null])->saveQuietly();

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('hrZonesChangedAt', null));
});

it('shares the ISO timestamp of the last heart-rate-zone change', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $changedAt = Carbon::parse('2026-05-20 04:00:00');
    $profile = RunnerProfile::factory()->for($user)->create();
    $profile->forceFill(['hr_zones_changed_at' => $changedAt])->saveQuietly();

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('hrZonesChangedAt', $changedAt->toIso8601String()));
});
