<?php

declare(strict_types=1);

use Carbon\Carbon;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\ActivityStream;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A run whose stored summary disagrees with what today's rules would produce:
 * the stored side claims wildly uneven pacing, the streams say otherwise.
 */
function runWithStaleSummary(User $user): Activity
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::today(),
        'distance' => 5000.0,
        'moving_time' => 1500,
        'stream_summary' => ['pace_variability_sec' => 95.3, 'negative_split' => true],
    ]);
    ActivityStream::factory()->for($activity)->create();

    return $activity;
}

it('fails loudly when there is nothing with stored streams to compare', function (): void {
    $this->artisan('run:compare-recalibration')
        ->expectsOutputToContain('No activities with stored streams')
        ->assertFailed();
});

it('reports both sides of every distribution without writing anything', function (): void {
    $user = User::factory()->create();
    $activity = runWithStaleSummary($user);
    $before = ActivityDetail::query()->where('activity_id', $activity->id)->value('stream_summary');

    $this->artisan('run:compare-recalibration')
        ->expectsOutputToContain('Nothing was written')
        ->expectsOutputToContain('Pace consistency')
        ->expectsOutputToContain('Rarity tier')
        ->expectsOutputToContain('Special move')
        ->expectsOutputToContain('Recomputed rarity score percentiles')
        ->assertSuccessful();

    // The whole point: it must stay safe to run against prod, repeatedly.
    expect(ActivityDetail::query()->where('activity_id', $activity->id)->value('stream_summary'))
        ->toEqual($before);
});

it('scopes to one user when asked', function (): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    runWithStaleSummary($mine);
    runWithStaleSummary($theirs);
    runWithStaleSummary($theirs);

    $this->artisan('run:compare-recalibration', ['--user' => $mine->id])
        ->expectsOutputToContain('Compared 1 runs')
        ->assertSuccessful();
});

it('counts a run that never got a card as its own bucket rather than fataling', function (): void {
    $user = User::factory()->create();
    runWithStaleSummary($user);

    expect(RunCard::query()->count())->toBe(0);

    $this->artisan('run:compare-recalibration')
        ->expectsOutputToContain('tidak ada kartu')
        ->assertSuccessful();
});
