<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Every page a newly-signed-up athlete can reach, walked with nothing behind
 * it. A second real account is the one thing this project has never had, so
 * the empty state is the state least likely to have been seen — and a 500 on
 * any of these is the worst possible first impression.
 */
const FRESH_ACCOUNT_PAGES = ['/', '/history', '/trends', '/race', '/plan', '/inbox', '/profile', '/settings'];

it('renders every page for an account with no activity at all', function (string $path): void {
    Carbon::setTestNow('2026-09-08 10:00:00'); // a Tuesday
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)->post('/onboarding', [
        'experience_level' => 'new_to_running',
        'goal_type' => 'base',
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get($path)->assertSuccessful();

    Carbon::setTestNow();
})->with(FRESH_ACCOUNT_PAGES);

it('renders every page for an account whose Strava history has not arrived yet', function (string $path): void {
    Carbon::setTestNow('2026-09-08 10:00:00');
    $user = User::factory()->create(['onboarded_at' => null]);

    // Onboarding submitted with a race goal, before a single activity has
    // finished importing — the exact window between connect and backfill.
    $this->actingAs($user)->post('/onboarding', [
        'race_date' => Carbon::today()->addMonths(4)->toDateString(),
        'distance_m' => 21_097,
        'goal_time_sec' => 7_200,
        'name' => 'A half',
        'experience_level' => 'returning',
        'goal_type' => 'race',
        'sessions_per_week' => 4,
    ])->assertSessionHasNoErrors();

    $this->actingAs($user)->get($path)->assertSuccessful();

    Carbon::setTestNow();
})->with(FRESH_ACCOUNT_PAGES);
