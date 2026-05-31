<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\StravaConnection;
use App\Services\Run\Ingest\ActivityPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    Cache::flush();
    RateLimiter::clear('strava-api:15min');
    RateLimiter::clear('strava-api:daily');
    Carbon::setTestNow('2026-05-20 12:00:00');
    config()->set('ai.backfill_threshold_hours', 24);
    config()->set('ai.backfill_stagger_seconds', 360);
    $this->pipeline = app(ActivityPipeline::class);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function backfillIngestSeed(string $startDate, ?int $userId = null): Activity
{
    $activity = $userId === null
        ? Activity::factory()->create(['strava_external_id' => random_int(1, 1_000_000)])
        : Activity::factory()->create(['user_id' => $userId, 'strava_external_id' => random_int(1, 1_000_000)]);

    if (! StravaConnection::query()->where('user_id', $activity->user_id)->exists()) {
        StravaConnection::factory()->for($activity->user)->create([
            'access_token' => 'tok',
            'token_expires_at' => Carbon::now()->addHours(2),
        ]);
    }

    Http::fake([
        "strava.com/api/v3/activities/{$activity->strava_external_id}" => Http::response([
            'name' => 'Run',
            'start_date_local' => $startDate,
            'distance' => 5000.0,
            'moving_time' => 1500,
            'elapsed_time' => 1500,
            'average_speed' => 3.33,
            'total_elevation_gain' => 10.0,
            'has_heartrate' => false,
            'splits_metric' => [],
            'map' => ['summary_polyline' => 'poly'],
            'start_latlng' => null,
        ]),
        "strava.com/api/v3/activities/{$activity->strava_external_id}/streams*" => Http::response([]),
    ]);

    return $activity;
}

it('fresh activities (started within threshold) dispatch with zero delay', function (): void {
    $activity = backfillIngestSeed('2026-05-20 06:00:00'); // 6 hours ago

    $this->pipeline->ingest($activity);

    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->delay === null || (int) $job->delay === 0,
    );
    expect(Cache::has("ai.backfill.next-slot:{$activity->user_id}"))->toBeFalse();
});

it('backfilled activities dispatch with staggered delay per user', function (): void {
    $userActivity1 = backfillIngestSeed('2026-04-01 06:00:00'); // ~50 days ago

    $this->pipeline->ingest($userActivity1);

    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $userActivity1->id && ($job->delay === null || (int) $job->delay === 0),
    );

    expect(Cache::has("ai.backfill.next-slot:{$userActivity1->user_id}"))->toBeTrue();

    Bus::fake();

    $userActivity2 = backfillIngestSeed('2026-04-02 06:00:00', $userActivity1->user_id);
    $this->pipeline->ingest($userActivity2);

    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $userActivity2->id && $job->delay !== null && (int) $job->delay >= 350,
    );
});

it('backfill stagger is isolated per user', function (): void {
    $userA = backfillIngestSeed('2026-04-01 06:00:00');
    $this->pipeline->ingest($userA);
    Bus::fake();

    $userB = backfillIngestSeed('2026-04-01 06:00:00');
    $this->pipeline->ingest($userB);

    // User B's first backfill should NOT inherit user A's slot
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $userB->id && ($job->delay === null || (int) $job->delay === 0),
    );
});

it('logs ai.backfill.queued when a non-zero delay is applied', function (): void {
    $first = backfillIngestSeed('2026-04-01 06:00:00');
    $this->pipeline->ingest($first);
    Bus::fake();
    Log::spy();

    $second = backfillIngestSeed('2026-04-02 06:00:00', $first->user_id);
    $this->pipeline->ingest($second);

    Log::shouldHaveReceived('info')->atLeast()->once()->with(
        'ai.backfill.queued',
        Mockery::on(fn (array $ctx): bool => isset($ctx['delay_sec'], $ctx['user_id']) && $ctx['delay_sec'] > 0),
    );
});
