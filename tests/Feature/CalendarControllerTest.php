<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the Kalender page for the current month by default', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Kalender')
            ->where('month', Carbon::today()->format('Y-m'))
            ->has('monthLabel')
            ->has('cells'));
});

it('honors ?month=YYYY-MM when valid', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=2026-01')
        ->assertInertia(fn (Assert $page) => $page->where('month', '2026-01'));
});

it('falls back to today\'s month when ?month is invalid', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=bogus')
        ->assertInertia(fn (Assert $page) => $page->where('month', Carbon::today()->format('Y-m')));
});

it('falls back to today\'s month when ?month parses but is impossible (Carbon throws)', function (): void {
    // 9999-99 passes the YYYY-MM regex but Carbon::parse refuses month 99.
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=9999-99')
        ->assertInertia(fn (Assert $page) => $page->where('month', Carbon::today()->format('Y-m')));
});

it('exposes prev / next month strings for navigation', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->where('prevMonth', '2026-04')
            ->where('nextMonth', '2026-06'));
});

it('pads the grid to whole Mon-Sun weeks (divisible by 7)', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->has('cells', fn (Assert $cells) => $cells->etc())
            ->where('cells', fn ($cells) => count($cells) % 7 === 0));
});

it('aggregates multiple runs on the same day into one cell', function (): void {
    $user = User::factory()->create();
    foreach ([3000, 4000] as $distance) {
        $activity = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::create(2026, 5, 15),
            'distance' => $distance,
            'moving_time' => (int) ($distance / 1000 * 360),
            'average_heartrate' => 150,
            'trimp_edwards' => 20.0,
        ]);
    }

    $this->actingAs($user)->get('/kalender?month=2026-05')
        ->assertInertia(fn (Assert $page) => $page
            ->where('cells', function ($cells) {
                $cell = collect($cells)->firstWhere('date', '2026-05-15');
                return $cell !== null
                    && abs(((float) $cell['distance_km']) - 7.0) < 0.01
                    && $cell['activity_id'] === null; // multi-run days don't link
            }));
});
