<?php

declare(strict_types=1);

use App\Jobs\Story\GenerateStoryLineJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Services\Run\Story\Temari;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('forwards to Temari for the resolved activity', function (): void {
    $activity = Activity::factory()->create();
    ActivityDetail::factory()->for($activity)->create();

    $temari = Mockery::mock(Temari::class);
    $temari->shouldReceive('postRunLine')
        ->once()
        ->withArgs(fn (Activity $a, ActivityDetail $d): bool => $a->is($activity));

    (new GenerateStoryLineJob($activity->id))->handle($temari);
});

it('no-ops on a missing activity or missing detail', function (): void {
    $temari = Mockery::mock(Temari::class);
    $temari->shouldNotReceive('postRunLine');

    (new GenerateStoryLineJob(999_999))->handle($temari);

    $activityWithoutDetail = Activity::factory()->create();
    (new GenerateStoryLineJob($activityWithoutDetail->id))->handle($temari);
});
