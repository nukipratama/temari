<?php

declare(strict_types=1);

use App\Jobs\Strava\ResyncActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\ThrottlesExceptions;

uses(RefreshDatabase::class);

it('re-ingests the activity', function (): void {
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

    new ResyncActivityJob($activity->id)->handle($pipeline);
});

it('quietly no-ops if the activity was deleted before the job runs', function (): void {
    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldNotReceive('ingest');

    new ResyncActivityJob(999_999)->handle($pipeline);
});

it('is unique per activity id so a duplicate webhook is not re-dispatched as a duplicate', function (): void {
    $job = new ResyncActivityJob(4242);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('4242')
        ->and($job->uniqueFor)->toBe(6 * 3600);
});

it('registers the same ThrottlesExceptions middleware as the ingest job', function (): void {
    $middleware = new ResyncActivityJob(1)->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(ThrottlesExceptions::class);
});

it('is governed by a retry window, not a fixed $tries cap', function (): void {
    $job = new ResyncActivityJob(1);

    expect($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp())
        ->and(property_exists($job, 'tries') ? $job->tries : null)->toBeNull();
});
