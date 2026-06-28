<?php

declare(strict_types=1);

use App\Jobs\Strava\ResyncActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\ThrottlesExceptions;

uses(RefreshDatabase::class);

it('re-ingests the activity and re-narrates when it is the chain head', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->create([
        'activity_id' => $activity->id,
        'start_date_local' => now(),
    ]);

    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('ingest')
        ->once()
        ->withArgs(fn (Activity $arg): bool => $arg->is($activity));

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('requestActivityGroup')
        ->once()
        ->withArgs(fn (Activity $arg, bool $invalidate): bool => $arg->is($activity) && $invalidate === true);

    (new ResyncActivityJob($activity->id))->handle($pipeline, $service);
});

it('re-ingests but does NOT re-narrate a mid-history activity', function (): void {
    $user = User::factory()->create();

    $old = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->create([
        'activity_id' => $old->id,
        'start_date_local' => now()->subDays(5),
    ]);

    $newer = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->create([
        'activity_id' => $newer->id,
        'start_date_local' => now(),
    ]);

    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('ingest')->once();

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('requestActivityGroup');

    (new ResyncActivityJob($old->id))->handle($pipeline, $service);
});

it('quietly no-ops if the activity was deleted before the job runs', function (): void {
    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldNotReceive('ingest');
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('requestActivityGroup');

    (new ResyncActivityJob(999_999))->handle($pipeline, $service);
});

it('registers the same ThrottlesExceptions middleware as the ingest job', function (): void {
    $middleware = (new ResyncActivityJob(1))->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(ThrottlesExceptions::class);
});

it('is governed by a retry window, not a fixed $tries cap', function (): void {
    $job = new ResyncActivityJob(1);

    expect($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp())
        ->and(property_exists($job, 'tries') ? $job->tries : null)->toBeNull();
});
