<?php

declare(strict_types=1);

use App\Livewire\Pulse\SystemControl;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// The share caches the global pause signal under a fixed key, so clear it
// between cases to keep one test's value from leaking into the next.
beforeEach(fn () => Cache::flush());

it('reflects a /pulse toggle on the very next request rather than after the TTL', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('stravaPaused', false));

    Livewire::test(SystemControl::class)->call('toggleStrava');

    $this->actingAs($user)->get('/profile')
        ->assertInertia(fn (Assert $page) => $page->where('stravaPaused', true));
});
