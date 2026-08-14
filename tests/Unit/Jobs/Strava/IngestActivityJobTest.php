<?php

declare(strict_types=1);

use App\Enums\StravaReadPriority;
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

it('hands the pipeline the priority it was dispatched with', function (): void {
    $activity = Activity::factory()->create();

    $pipeline = Mockery::mock(ActivityPipeline::class);
    $pipeline->shouldReceive('ingest')
        ->once()
        ->withArgs(fn (Activity $arg, StravaReadPriority $priority): bool => $arg->is($activity)
            && $priority === StravaReadPriority::Background);

    new IngestActivityJob($activity->id, StravaReadPriority::Background)->handle($pipeline);
});

it('defaults to live priority so an unqualified dispatch keeps the full budget', function (): void {
    expect(new IngestActivityJob(1)->priority)->toBe(StravaReadPriority::Live);
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

it('does not let a stalled browsing tier hold back live ingest', function (): void {
    $fakeJob = fn (): object => new class () {
        public int $releaseCount = 0;

        public function release(int $delay): void
        {
            $this->releaseCount++;
        }

        public function fail(Throwable $e): void
        {
        }

        public function uuid(): string
        {
            return 'job-uuid';
        }
    };

    $rateLimited = function (): never {
        throw new StravaRateLimitedException('rate limited');
    };

    // Exhaust the background tier's throttle circuit: 50 backoffs inside its
    // decay window, the shape of a signup burst scrolling old runs.
    $background = new IngestActivityJob(1, StravaReadPriority::Background)->middleware()[0];
    $browsing = $fakeJob();
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $background->handle($browsing, $rateLimited);
    }

    // The circuit is now open for browsing: it releases without even running.
    $browsingAfter = $fakeJob();
    $background->handle($browsingAfter, fn () => throw new RuntimeException('should not run'));
    expect($browsingAfter->releaseCount)->toBe(1);

    // A freshly-webhooked run still executes: separate key, separate circuit.
    $ran = false;
    $live = new IngestActivityJob(2)->middleware()[0];
    $live->handle($fakeJob(), function () use (&$ran): void {
        $ran = true;
    });

    expect($ran)->toBeTrue();
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
