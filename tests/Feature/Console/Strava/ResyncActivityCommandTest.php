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
