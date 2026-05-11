<?php

declare(strict_types=1);

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forwards to the ActivityPipeline for the resolved activity', function (): void {
    $activity = Activity::factory()->create();

    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('ingest')
        ->once()
        ->withArgs(fn (Activity $arg): bool => $arg->is($activity));

    (new IngestActivityJob($activity->id))->handle($pipeline);
});

it('quietly no-ops if the activity has been deleted before the job runs', function (): void {
    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldNotReceive('ingest');

    (new IngestActivityJob(999_999))->handle($pipeline);
});
