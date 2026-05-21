<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the Kalender page with default 12-month window', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Kalender')
            ->where('months', 12)
            ->has('cells'));
});

it('returns one cell per day across the requested month window', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays(10),
        'distance' => 6200,
        'trimp_edwards' => 55.5,
    ]);

    $this->actingAs($user)->get('/kalender?months=1')
        ->assertInertia(fn (Assert $page) => $page
            ->where('months', 1)
            ->has('cells', fn (Assert $cells) => $cells->etc()));
});

it('clamps months query to 1..24', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?months=999')
        ->assertInertia(fn (Assert $page) => $page->where('months', 24));

    $this->actingAs($user)->get('/kalender?months=-3')
        ->assertInertia(fn (Assert $page) => $page->where('months', 1));
});
