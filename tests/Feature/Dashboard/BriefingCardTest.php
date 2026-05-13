<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-05-11 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('renders the Briefing Temari hero on the dashboard', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'trimp_edwards' => 60.0,
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Dashboard')
            ->has('briefing.headlineLine')
            ->has('briefing.suggestionLine')
            ->has('briefing.vibeState')
            ->has('briefing.mood'));
});

it('shows "Lari hari ini" streak chip when there is a run today', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'trimp_edwards' => 60.0,
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('briefing.streakLabel', 'Lari hari ini'));
});

it('escalates streak chip past 4 days away', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today()->subDays(6),
        'trimp_edwards' => 60.0,
    ]);

    $this->actingAs($user)->get('/dashboard')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('briefing.streakLabel', 'Sudah 6 hari nih'));
});

it('renders the hibernating briefing for a user with no runs', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('briefing.vibeState', 'hibernating')
            ->where('briefing.vibeLabel', 'Hibernasi'));
});

it('still renders the empty-state alongside the briefing when no synced activities', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/dashboard')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->has('briefing')
            ->where('load', null));
});
