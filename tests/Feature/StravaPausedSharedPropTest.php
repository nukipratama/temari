<?php

declare(strict_types=1);

use App\Livewire\Pulse\SystemControl;
use App\Models\User;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The share caches the global pause signal under a fixed key, so clear it
// between cases to keep one test's value from leaking into the next.
beforeEach(fn () => Cache::flush());

it('shares true when the Strava kill-switch is off', function (): void {
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);

    $this->actingAs(User::factory()->create())->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('stravaPaused', true));
});

it('shares false while Strava is enabled', function (): void {
    $this->actingAs(User::factory()->create())->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('stravaPaused', false));
});

it('shares false for a guest', function (): void {
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);

    $this->get('/login')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('stravaPaused', false));
});

it('reflects a /pulse toggle on the very next request rather than after the TTL', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/rekor')
        ->assertInertia(fn (Assert $page) => $page->where('stravaPaused', false));

    Livewire::test(SystemControl::class)->call('toggleStrava');

    $this->actingAs($user)->get('/rekor')
        ->assertInertia(fn (Assert $page) => $page->where('stravaPaused', true));
});
