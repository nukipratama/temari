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
        ->assertSee('API Rate Limits')
        ->assertSee('15 min');
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

it('shows webhook subscribed when subscription id is configured', function (): void {
    config(['services.strava.webhook_subscription_id' => '42']);

    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('Webhook subscribed')
        ->assertSee('ID: 42');
});

it('shows webhook not configured when subscription id is empty', function (): void {
    config(['services.strava.webhook_subscription_id' => null]);

    Livewire::test(StravaHealth::class)
        ->assertOk()
        ->assertSee('Webhook not configured');
});
