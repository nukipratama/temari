<?php

declare(strict_types=1);

// Backfill stagger now lives in DispatchPostRunAnalysis (the queued listener that
// owns the post-ingest AI fan-out), so these cases drive the listener directly.
// Kept here as the backfill-delay regression suite.

use App\Events\ActivityIngested;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Listeners\DispatchPostRunAnalysis;
use App\Models\Activity;
use App\Models\ActivityDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Bus::fake();
    Cache::flush();
    Carbon::setTestNow('2026-05-20 12:00:00');
    config()->set('ai.backfill_threshold_hours', 24);
    config()->set('ai.backfill_stagger_seconds', 360);
});

afterEach(function (): void {
    Carbon::setTestNow();
});

function backfillSeed(string $startDate, ?int $userId = null): Activity
{
    $activity = $userId === null
        ? Activity::factory()->create(['analyzed_at' => Carbon::now()])
        : Activity::factory()->create(['user_id' => $userId, 'analyzed_at' => Carbon::now()]);

    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse($startDate),
        'distance' => 5000.0,
        'moving_time' => 1500,
    ]);

    return $activity;
}

function fireDispatch(Activity $activity): void
{
    app(DispatchPostRunAnalysis::class)->handle(new ActivityIngested($activity->id));
}

it('fresh activities (started within threshold) dispatch with zero delay', function (): void {
    $activity = backfillSeed('2026-05-20 06:00:00'); // 6 hours ago

    fireDispatch($activity);

    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->delay === null || (int) $job->delay === 0,
    );
    expect(Cache::has("ai.backfill.next-slot:{$activity->user_id}"))->toBeFalse();
});

it('backfilled activities dispatch with staggered delay per user', function (): void {
    $userActivity1 = backfillSeed('2026-04-01 06:00:00'); // ~50 days ago

    fireDispatch($userActivity1);

    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $userActivity1->id && ($job->delay === null || (int) $job->delay === 0),
    );

    expect(Cache::has("ai.backfill.next-slot:{$userActivity1->user_id}"))->toBeTrue();

    Bus::fake();

    $userActivity2 = backfillSeed('2026-04-02 06:00:00', $userActivity1->user_id);
    fireDispatch($userActivity2);

    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $userActivity2->id && $job->delay !== null && (int) $job->delay >= 350,
    );
});

it('backfill stagger is isolated per user', function (): void {
    $userA = backfillSeed('2026-04-01 06:00:00');
    fireDispatch($userA);
    Bus::fake();

    $userB = backfillSeed('2026-04-01 06:00:00');
    fireDispatch($userB);

    // User B's first backfill should NOT inherit user A's slot
    Bus::assertDispatched(
        AnalyzeActivityJob::class,
        fn (AnalyzeActivityJob $job): bool => $job->subjectId === $userB->id && ($job->delay === null || (int) $job->delay === 0),
    );
});

it('logs ai.backfill.queued when a non-zero delay is applied', function (): void {
    $first = backfillSeed('2026-04-01 06:00:00');
    fireDispatch($first);
    Bus::fake();
    Log::spy();

    $second = backfillSeed('2026-04-02 06:00:00', $first->user_id);
    fireDispatch($second);

    Log::shouldHaveReceived('info')->atLeast()->once()->with(
        'ai.backfill.queued',
        Mockery::on(fn (array $ctx): bool => isset($ctx['delay_sec'], $ctx['user_id']) && $ctx['delay_sec'] > 0),
    );
});
