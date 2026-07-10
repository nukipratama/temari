<?php

declare(strict_types=1);

use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares true when a live connection lacks the profile:read_all scope', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('stravaZoneScopeMissing', true));
});

it('shares false when the connection already has profile:read_all', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all,profile:read_all']);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('stravaZoneScopeMissing', false));
});

it('shares false when the connection is revoked', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->revoked()->create(['scopes' => 'read,activity:read_all']);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('stravaZoneScopeMissing', false));
});

it('shares false when the user has no Strava connection', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('stravaZoneScopeMissing', false));
});

it('never nudges the demo user, even with a scope-missing connection', function (): void {
    $user = User::factory()->create(['is_demo' => true]);
    StravaConnection::factory()->for($user)->create(['scopes' => 'read,activity:read_all']);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('stravaZoneScopeMissing', false));
});
