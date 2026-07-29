<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Run\Metrics\SummaryRecomputer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('loads the activity with its detail and stream, then hands it to the pipeline', function (): void {
    $activity = Activity::factory()->create();

    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('recomputeSummary')
        ->once()
        ->withArgs(fn (Activity $passed): bool => $passed->is($activity)
            && $passed->relationLoaded('detail')
            && $passed->relationLoaded('stream'));

    new SummaryRecomputer($pipeline)->recomputeFromStoredStreams($activity->id);
});

it('is a no-op when the activity does not exist', function (): void {
    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldNotReceive('recomputeSummary');

    new SummaryRecomputer($pipeline)->recomputeFromStoredStreams(404404);
});
