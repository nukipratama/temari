<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\StoryLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('lists the user\'s analyzed runs in reverse chronological order', function (): void {
    $user = User::factory()->create();
    $older = Activity::factory()->for($user)->analyzed()->create();
    $newer = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($older)->create(['name' => 'Older Run', 'start_date_local' => Carbon::now()->subDays(2)]);
    ActivityDetail::factory()->for($newer)->create(['name' => 'Newer Run', 'start_date_local' => Carbon::now()]);

    $response = $this->actingAs($user)->get('/runs');

    $response->assertSuccessful()
        ->assertSeeText('Newer Run')
        ->assertSeeText('Older Run');
});

it('renders the empty state when the user has no analyzed runs yet', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/runs')
        ->assertSuccessful()
        ->assertSeeText('Belum ada run yang dianalisis');
});

it('shows a single run detail with Temari speech + technical fold', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'name' => 'Morning Run',
        'distance' => 10000,
        'moving_time' => 3600,
        'elapsed_time' => 3600,
        'stream_summary' => [
            'time_in_zone_pct' => ['Z2' => 60, 'Z3' => 30, 'Z4' => 10],
            'per_km' => [['km' => 1, 'pace' => '6:00', 'avg_hr' => 150]],
            'decoupling_pct' => 4.2,
        ],
    ]);
    RunCard::factory()->for($activity)->create(['special_move' => 'Paru-paru Baja']);
    StoryLine::factory()->for($activity)->create([
        'user_id' => $user->id,
        'speech' => 'Run yang solid, paru-paru baja keluar.',
    ]);

    $response = $this->actingAs($user)->get("/runs/{$activity->id}");

    $response->assertSuccessful()
        ->assertSeeText('Morning Run')
        ->assertSeeText('Temari')
        ->assertSeeText('Run yang solid, paru-paru baja keluar.')
        ->assertSeeText('Paru-paru Baja')
        ->assertSeeText('Detail teknis')
        ->assertSeeText('Splits per KM');
});

it('404s when trying to view another user\'s run', function (): void {
    $other = User::factory()->create();
    $activity = Activity::factory()->for($other)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $me = User::factory()->create();
    $this->actingAs($me)->get("/runs/{$activity->id}")->assertNotFound();
});

it('404s when the activity has not been analyzed yet', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create(); // no detail row

    $this->actingAs($user)->get("/runs/{$activity->id}")->assertNotFound();
});
