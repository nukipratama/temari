<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Enums\StravaReadPriority;
use App\Enums\StravaReadSource;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Models\User;
use App\Models\Analytics\StravaRead;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Run\Ingest\DetailHydrator;
use App\Services\Run\Ingest\SyncOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    RateLimiter::clear('strava-api:15min');
    RateLimiter::clear('strava-api:daily');
});

function runnerWithConnection(): User
{
    $user = User::factory()->create(['is_demo' => false]);
    StravaConnection::factory()->for($user)->create([
        'access_token' => 'tok',
        'token_expires_at' => now()->addHours(2),
        'revoked_at' => null,
    ]);

    return $user;
}

/**
 * Drives one queued job through its own throttle middleware, the way a worker
 * would, and reports whether the job body ran or was released back onto the
 * queue.
 *
 * @return array{ran: bool, released: int}
 */
function runThroughThrottle(IngestActivityJob $job): array
{
    $queued = new class () {
        public int $releaseCount = 0;

        public bool $failed = false;

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

    $ran = false;
    $job->middleware()[0]->handle($queued, function () use ($job, &$ran): void {
        $ran = true;
        $job->handle(app(ActivityPipeline::class));
    });

    return ['ran' => $ran, 'released' => $queued->releaseCount];
}

function fakeStravaDetailEndpoints(): void
{
    Http::fake([
        'strava.com/api/v3/activities/*/streams*' => Http::response([]),
        'strava.com/api/v3/activities/*' => Http::response([
            'id' => 1,
            'sport_type' => 'Run',
            'name' => 'Evening',
            'start_date_local' => now()->toIso8601String(),
            'distance' => 10_000.0,
            'moving_time' => 3_000,
            'elapsed_time' => 3_050,
            'average_speed' => 3.33,
            'has_heartrate' => true,
            'average_heartrate' => 152.0,
            'max_heartrate' => 176.0,
        ]),
    ]);
}

it('lets a freshly-finished run ingest while a browsing fetch queues, with the pool at the reserve floor', function (): void {
    fakeStravaDetailEndpoints();

    // 150 of the 200/15min read pool spent: the reserved quarter is all that's left.
    for ($i = 0; $i < 150; $i++) {
        RateLimiter::hit('strava-api:15min', 15 * 60);
    }

    $archiveRun = Activity::factory()->for(runnerWithConnection())->summaryOnly()->create();
    $freshRun = Activity::factory()->for(runnerWithConnection())->summaryOnly()->create();

    $browsing = runThroughThrottle(new IngestActivityJob($archiveRun->id, StravaReadPriority::Background));
    $live = runThroughThrottle(new IngestActivityJob($freshRun->id, StravaReadPriority::Live));

    // The browsing fetch is deferred, not dropped: it went back on the queue and
    // its run is untouched, still honestly summary-only.
    expect($browsing['released'])->toBe(1)
        ->and($archiveRun->refresh()->ingest_state)->toBe(IngestState::Summary);

    // The freshly-finished run got through on the reserve.
    expect($live['ran'])->toBeTrue()
        ->and($freshRun->refresh()->ingest_state)->toBe(IngestState::Detailed);

    expect(StravaRead::query()->count())->toBe(2)
        ->and(StravaRead::query()->pluck('source')->unique()->all())->toBe([StravaReadSource::IngestSweep]);

    Http::assertNotSent(fn ($request): bool => str_contains(
        (string) $request->url(),
        "/activities/{$archiveRun->strava_external_id}",
    ));
});

it('routes opening an old run to the background tier and a webhook push to the live one', function (): void {
    Queue::fake();

    $user = runnerWithConnection();
    $archiveRun = Activity::factory()->for($user)->summaryOnly()->create();

    app(DetailHydrator::class)->hydrate($archiveRun->id);
    app(SyncOrchestrator::class)->syncSingleActivity($user, 987_654_321);

    $freshStub = Activity::query()->withStubs()
        ->where('strava_external_id', 987_654_321)
        ->sole();

    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->activityId === $archiveRun->id
            && $job->priority === StravaReadPriority::Background
            && $job->source === StravaReadSource::Hydration,
    );
    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->activityId === $freshStub->id
            && $job->priority === StravaReadPriority::Live
            && $job->source === StravaReadSource::Webhook,
    );
});

it('records the webhook source for both reads dispatched by the queued ingest', function (): void {
    Queue::fake();
    fakeStravaDetailEndpoints();

    $user = runnerWithConnection();
    expect(app(SyncOrchestrator::class)->syncSingleActivity($user, 987_654_321))->toBeTrue();

    $activity = Activity::query()->withStubs()->where('strava_external_id', 987_654_321)->sole();
    new IngestActivityJob($activity->id, StravaReadPriority::Live, StravaReadSource::Webhook)
        ->handle(app(ActivityPipeline::class));

    $reads = StravaRead::query()->orderBy('endpoint')->get();
    expect($reads)->toHaveCount(2)
        ->and($reads->pluck('source')->unique()->all())->toBe([StravaReadSource::Webhook])
        ->and($reads->pluck('priority')->unique()->all())->toBe([StravaReadPriority::Live])
        ->and($reads->pluck('endpoint')->all())->toBe(['activity_detail', 'activity_streams']);
});

it('records hydrated detail and stream reads as background hydration', function (): void {
    Queue::fake();
    fakeStravaDetailEndpoints();

    $activity = Activity::factory()->for(runnerWithConnection())->summaryOnly()->create();
    expect(app(DetailHydrator::class)->hydrate($activity->id))->toBeTrue();

    new IngestActivityJob($activity->id, StravaReadPriority::Background, StravaReadSource::Hydration)
        ->handle(app(ActivityPipeline::class));

    $reads = StravaRead::query()->orderBy('endpoint')->get();
    expect($reads)->toHaveCount(2)
        ->and($reads->pluck('source')->unique()->all())->toBe([StravaReadSource::Hydration])
        ->and($reads->pluck('priority')->unique()->all())->toBe([StravaReadPriority::Background]);
});
