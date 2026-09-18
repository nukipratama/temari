<?php

declare(strict_types=1);

use App\Enums\StravaReadPriority;
use App\Jobs\Strava\HydrateBacklogForUserJob;
use App\Jobs\Strava\IngestActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\StravaConnection;
use App\Models\User;
use App\Support\Config\AppConfig;
use App\Support\Config\AppConfigKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
});

function drainJobUser(): User
{
    $user = User::factory()->create(['is_demo' => false]);
    StravaConnection::factory()->for($user)->create(['revoked_at' => null]);

    return $user;
}

function drainJobBacklogRun(User $user, string $startedAt = '2026-01-01 06:00:00'): Activity
{
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startedAt]);

    return $activity;
}

/**
 * `Queue::fake()` intercepts `dispatch()`/`dispatchSync()` alike for a
 * `ShouldQueue` job (both route through `dispatchToQueue()`), so exercising
 * the job's own logic needs a direct `handle()` call, resolved through the
 * container the same way the real queue worker would.
 */
function runHydrateBacklogForUser(int $userId): void
{
    app()->call([new HydrateBacklogForUserJob($userId), 'handle']);
}

it('no-ops when the Strava kill-switch is off', function (): void {
    $user = drainJobUser();
    drainJobBacklogRun($user);
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);

    runHydrateBacklogForUser($user->id);

    Queue::assertNothingPushed();
});

it('queues that user\'s summary-only backlog at background priority the moment it is dispatched', function (): void {
    $user = drainJobUser();
    drainJobBacklogRun($user);
    drainJobBacklogRun($user);

    runHydrateBacklogForUser($user->id);

    Queue::assertPushed(IngestActivityJob::class, 2);
    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->priority === StravaReadPriority::Background,
    );
});

it('hydrates that user oldest-first', function (): void {
    $user = drainJobUser();
    $oldest = drainJobBacklogRun($user, '2020-05-01 06:00:00');
    $newest = drainJobBacklogRun($user, '2026-08-01 06:00:00');

    runHydrateBacklogForUser($user->id);

    $order = Queue::pushed(IngestActivityJob::class)->map(fn (IngestActivityJob $job): int => $job->activityId)->all();

    expect($order)->toBe([$oldest->id, $newest->id]);
});

it('does not touch another user\'s backlog', function (): void {
    $user = drainJobUser();
    $other = drainJobUser();
    drainJobBacklogRun($other);

    runHydrateBacklogForUser($user->id);

    Queue::assertNothingPushed();
});

it('fetches nothing extra once the background read headroom is spent', function (): void {
    $user = drainJobUser();
    drainJobBacklogRun($user);

    $this->travelTo(now());
    for ($i = 0; $i < 150; $i++) {
        RateLimiter::hit('strava-api:15min', 900);
    }

    runHydrateBacklogForUser($user->id);

    Queue::assertNothingPushed();
});

/**
 * The cron tick and this job both bottom out in
 * {@see \App\Services\Run\Ingest\DetailHydrator::hydrate()}, which dispatches a
 * `ShouldBeUnique` {@see IngestActivityJob} keyed on the activity id and held
 * for a 6-hour retry window. Running both back-to-back before either's
 * dispatched job actually executes — the same window a real concurrent tick
 * would race in, since neither flips `ingest_state` until its queued
 * IngestActivityJob runs — must not double-queue the same run.
 */
it('does not double-hydrate a run a concurrent cron tick already claimed', function (): void {
    $user = drainJobUser();
    drainJobBacklogRun($user, '2020-05-01 06:00:00');
    drainJobBacklogRun($user, '2026-08-01 06:00:00');

    runHydrateBacklogForUser($user->id);
    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    Queue::assertPushed(IngestActivityJob::class, 2);
});
