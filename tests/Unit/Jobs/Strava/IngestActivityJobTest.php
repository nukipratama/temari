<?php

declare(strict_types=1);

use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Strava\Exceptions\StravaRateLimitedException;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('forwards to the ActivityPipeline for the resolved activity', function (): void {
    $activity = Activity::factory()->create();

    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('ingest')
        ->once()
        ->withArgs(fn (Activity $arg): bool => $arg->is($activity));

    new IngestActivityJob($activity->id)->handle($pipeline);
});

it('quietly no-ops if the activity has been deleted before the job runs', function (): void {
    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldNotReceive('ingest');

    new IngestActivityJob(999_999)->handle($pipeline);
});

it('registers a ThrottlesExceptions middleware so 429 backoffs do not burn attempts', function (): void {
    $middleware = new IngestActivityJob(1)->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(ThrottlesExceptions::class);
});

it('does not pin a fixed $tries cap that a rate-limit backoff loop could exhaust', function (): void {
    $job = new IngestActivityJob(1);

    expect($job->retryUntil())->toBeInstanceOf(DateTimeInterface::class)
        ->and($job->retryUntil()->getTimestamp())->toBeGreaterThan(now()->getTimestamp())
        ->and(property_exists($job, 'tries') ? $job->tries : null)->toBeNull();
});

it('survives many rate-limit backoffs without the throttle middleware failing the job', function (): void {
    $job = new IngestActivityJob(1);

    $next = function () {
        throw new StravaRateLimitedException('rate limited');
    };

    $released = 0;
    $fakeJob = new class ($released) {
        public bool $failed = false;

        public function __construct(public int &$releaseCount)
        {
        }

        public function release(int $delay): void
        {
            $this->releaseCount++;
        }

        public function fail(Throwable $e): void
        {
            $this->failed = true;
        }

        public function uuid(): string
        {
            return 'job-uuid';
        }
    };

    $middleware = $job->middleware()[0];

    for ($attempt = 0; $attempt < 10; $attempt++) {
        $middleware->handle($fakeJob, $next);
    }

    expect($fakeJob->failed)->toBeFalse()
        ->and($fakeJob->releaseCount)->toBe(10);
});

it('lets the throttle middleware re-raise a genuine non rate-limit failure', function (): void {
    // maxExceptions governs genuine failures; the throttle only catches 429s,
    // so any other throwable is re-raised for the job to count against it.
    $job = new IngestActivityJob(1);
    $middleware = $job->middleware()[0];

    $next = function () {
        throw new RuntimeException('genuine failure');
    };

    $fakeJob = new class () {
        public function uuid(): string
        {
            return 'job-uuid';
        }
    };

    expect(fn () => $middleware->handle($fakeJob, $next))
        ->toThrow(RuntimeException::class, 'genuine failure');

    expect($job->maxExceptions)->toBe(3);
});

it('is unique per activity id so a throttled stub is not re-dispatched as a duplicate', function (): void {
    $job = new IngestActivityJob(4242);

    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->uniqueId())->toBe('4242')
        ->and($job->uniqueFor)->toBe(6 * 3600);
});

it('logs the stuck activity when the job is finally marked failed', function (): void {
    Log::spy();

    new IngestActivityJob(123)->failed(new RuntimeException('boom'));

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $message === 'strava.ingest.failed'
            && $context['activity_id'] === 123
            && $context['reason'] === 'boom',
    );
});
