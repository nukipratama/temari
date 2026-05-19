<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders weekly snapshots', function (): void {
    $user = User::factory()->create();

    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 35.0,
        'runs' => 4,
        'form' => -7.4,
        'form_status' => 'optimal',
    ]);

    $this->actingAs($user)->get('/catatan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Catatan')
            ->has('snapshots', 1)
            ->where('snapshots.0.distance_km', 35));
});

it('shows empty snapshots when the user has no data', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/catatan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Catatan')
            ->where('snapshots', []));
});
