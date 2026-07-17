<?php

declare(strict_types=1);

use App\Jobs\Story\GenerateRunCardJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Story\RunCardFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forwards to RunCardFactory for the resolved activity', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create();

    $factory = Mockery::mock(RunCardFactory::class);
    $factory->shouldReceive('build')
        ->once()
        ->withArgs(fn (Activity $a, ActivityDetail $d): bool => $a->is($activity));

    new GenerateRunCardJob($activity->id)->handle($factory);
});

it('no-ops when the activity is missing', function (): void {
    $factory = Mockery::mock(RunCardFactory::class);
    $factory->shouldNotReceive('build');

    new GenerateRunCardJob(999_999)->handle($factory);
});

it('no-ops when detail has not been ingested yet', function (): void {
    $activity = Activity::factory()->create();

    $factory = Mockery::mock(RunCardFactory::class);
    $factory->shouldNotReceive('build');

    new GenerateRunCardJob($activity->id)->handle($factory);
});
