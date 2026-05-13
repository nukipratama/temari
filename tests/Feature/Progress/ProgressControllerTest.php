<?php

declare(strict_types=1);

use App\Models\PersonalRecord;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders weekly snapshots + PR ledger', function (): void {
    $user = User::factory()->create();

    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'distance_km' => 35.0,
        'runs' => 4,
        'form' => -7.4,
        'form_status' => 'optimal',
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500.0,
    ]);

    $this->actingAs($user)->get('/progress')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Progress')
            ->has('snapshots', 1)
            ->where('snapshots.0.distance_km', 35)
            ->has('personalRecords', 1)
            ->where('personalRecords.0.category', '5km'));
});

it('shows empty states when the user has no data', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/progress')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Progress')
            ->where('snapshots', [])
            ->where('personalRecords', []));
});
