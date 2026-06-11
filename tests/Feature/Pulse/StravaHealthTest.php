<?php

declare(strict_types=1);

use App\Livewire\Pulse\StravaHealth;
use App\Models\StravaConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders connection states and rate-limit headroom without error', function (): void {
    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('Connections')
        ->assertSee('stranded')
        ->assertSee('synced');
});

it('shows an ok health badge when there are no connection problems', function (): void {
    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('health: ok');
});

it('shows a warn health badge when a connection token has expired', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'revoked_at' => null,
        'token_expires_at' => Carbon::now()->subDay(),
    ]);

    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('health: warn');
});

it('shows an alert health badge when a connection is revoked', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create(['revoked_at' => Carbon::now()]);

    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('health: alert');
});

it('counts a revoked connection in the revoked bucket', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create([
        'revoked_at' => Carbon::now(),
    ]);

    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('revoked');
});

it('shows webhook subscribed without leaking the subscription id', function (): void {
    // Use a value unlikely to appear in Livewire-generated wire:key hashes.
    config(['services.strava.webhook_subscription_id' => 'sub_wh_test_9z8x7w']);

    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('Webhook subscribed')
        ->assertDontSee('ID:')
        ->assertDontSee('sub_wh_test_9z8x7w');
});

it('shows webhook not configured when subscription id is empty', function (): void {
    config(['services.strava.webhook_subscription_id' => null]);

    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('Webhook not configured');
});
