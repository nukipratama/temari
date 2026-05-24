<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PersonalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the PR ledger', function (): void {
    $user = User::factory()->create();

    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1500.0,
    ]);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Koleksi/Rekor')
            ->has('personalRecords', 1)
            ->where('personalRecords.0.category', '5km'));
});

it('shows empty PR ledger when the user has none', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Koleksi/Rekor')
            ->where('personalRecords', []));
});

it('computes hero scoreboard extras + progression series for a distance PR with splits', function (): void {
    Carbon::setTestNow('2026-05-20 12:00:00');
    $user = User::factory()->create();

    // The PR row + its source activity. splits_metric gets fed to splitsPaceSec;
    // value_sec drives milestoneFor's rounding heuristic (1751s → 29:00 target).
    $featuredActivity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($featuredActivity)->create([
        'name' => 'Sub-30 5K',
        'distance' => 5_000,
        'moving_time' => 1_751,
        'location_name' => 'Senayan',
        'weather_temp_c' => 28,
        'weather_humidity_pct' => 75,
        'start_date_local' => Carbon::parse('2026-05-18 06:30:00'),
        'splits_metric' => [
            ['distance' => 1_000, 'moving_time' => 360],
            ['distance' => 1_000, 'moving_time' => 350],
            ['distance' => 1_000, 'moving_time' => 345],
            ['distance' => 1_000, 'moving_time' => 350],
            ['distance' => 1_000, 'moving_time' => 346],
        ],
    ]);
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 1_751,
        'activity_id' => $featuredActivity->id,
        'set_at' => Carbon::parse('2026-05-18 06:30:00'),
    ]);

    // Two more in-window activities so progressionSeries' foreach body
    // actually iterates + the scale-to-target math + ksort fire.
    foreach ([['2026-04-12', 4_900, 1_800], ['2026-05-04', 5_100, 1_745]] as [$date, $dist, $mt]) {
        $a = Activity::factory()->for($user)->analyzed()->create();
        ActivityDetail::factory()->for($a)->create([
            'distance' => $dist,
            'moving_time' => $mt,
            'start_date_local' => Carbon::parse($date.' 07:00:00'),
        ]);
    }

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Koleksi/Rekor')
            ->where('featuredExtras.pr_id', fn (int $id): bool => $id > 0)
            ->where('featuredExtras.location_name', 'Senayan')
            ->where('featuredExtras.target_sec', 1_740)
            ->where('featuredExtras.delta_sec', 11)
            ->where('featuredExtras.splits_pace_sec', [360, 350, 345, 350, 346])
            ->where('progressionSeries.category', '5km')
            ->has('progressionSeries.weeks', 3)
            ->has('progressionSeries.times_sec', 3));

    Carbon::setTestNow();
});

it('returns null milestone target when the PR value_sec is non-positive', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '5km',
        'value_sec' => 0,
    ]);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredExtras.target_sec', null)
            ->where('featuredExtras.delta_sec', null));
});

it('milestoneFor rounds hour-scale times down to the next 5-minute increment', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'half_marathon',
        'value_sec' => 7_500, // 2:05:00 → target 2:00:00 (7200), delta 5:00 (300)
    ]);

    $this->actingAs($user)->get('/rekor')
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredExtras.target_sec', 7_200)
            ->where('featuredExtras.delta_sec', 300));
});

it('milestoneFor rounds sub-10-minute times down to the next 15s increment', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => '1km',
        'value_sec' => 300, // 5:00 → target 4:45 (285), delta 15
    ]);

    $this->actingAs($user)->get('/rekor')
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredExtras.target_sec', 285)
            ->where('featuredExtras.delta_sec', 15));
});

it('skips milestone + progression for effort PRs (non-distance categories)', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create([
        'category' => 'best_20min',
        'value_sec' => 320,
    ]);

    $this->actingAs($user)->get('/rekor')
        ->assertInertia(fn (Assert $page) => $page
            ->where('featuredExtras.target_sec', null)
            ->where('progressionSeries', null));
});
