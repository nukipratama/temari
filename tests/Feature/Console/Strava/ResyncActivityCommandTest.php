<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('re-runs the pipeline for the given activity', function (): void {
    $activity = Activity::factory()->create();

    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('ingest')
        ->once()
        ->withArgs(fn (Activity $arg): bool => $arg->is($activity));
    $this->app->instance(ActivityPipeline::class, $pipeline);

    $this->artisan('strava:resync-activity', ['activity' => $activity->id])
        ->expectsOutputToContain("Activity {$activity->id} re-ingested.")
        ->assertSuccessful();
});

it('returns a failure when the activity does not exist', function (): void {
    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldNotReceive('ingest');
    $this->app->instance(ActivityPipeline::class, $pipeline);

    $this->artisan('strava:resync-activity', ['activity' => 999_999])
        ->expectsOutputToContain('Activity 999999 not found.')
        ->assertFailed();
});

it('lets a pipeline failure propagate uncaught, unlike the batch strava:sync command', function (): void {
    // Deliberate design difference from SyncCommand (which swallows Throwable
    // per-user so one bad run doesn't kill a batch): this command is a
    // single-activity admin tool for iterating on compute logic, so a failure
    // should surface immediately with a real stack trace, not be swallowed.
    $activity = Activity::factory()->create();

    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('ingest')->once()->andThrow(new RuntimeException('boom'));
    $this->app->instance(ActivityPipeline::class, $pipeline);

    $this->artisan('strava:resync-activity', ['activity' => $activity->id])->run();
})->throws(RuntimeException::class, 'boom');
