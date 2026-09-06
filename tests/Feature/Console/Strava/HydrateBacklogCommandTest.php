<?php

declare(strict_types=1);

use App\Enums\StravaReadPriority;
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

function drainUser(bool $isDemo = false): User
{
    $user = User::factory()->create(['is_demo' => $isDemo]);
    StravaConnection::factory()->for($user)->create(['revoked_at' => null]);

    return $user;
}

function backlogRun(User $user, string $startedAt = '2026-01-01 06:00:00'): Activity
{
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $startedAt]);

    return $activity;
}

it('no-ops when the Strava kill-switch is off', function (): void {
    backlogRun(drainUser());
    app(AppConfig::class)->set(AppConfigKey::StravaEnabled, false);

    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('queues the summary-only backlog at background priority', function (): void {
    $user = drainUser();
    backlogRun($user);
    backlogRun($user);

    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    Queue::assertPushed(IngestActivityJob::class, 2);
    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->priority === StravaReadPriority::Background,
    );
});

it('hydrates newest-first', function (): void {
    $user = drainUser();
    backlogRun($user, '2020-05-01 06:00:00');
    $newest = backlogRun($user, '2026-08-01 06:00:00');

    $this->artisan('strava:hydrate-backlog', ['--batch' => 1])->assertSuccessful();

    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->activityId === $newest->id,
    );
    Queue::assertPushed(IngestActivityJob::class, 1);
});

it('leaves already-detailed runs alone', function (): void {
    $user = drainUser();
    Activity::factory()->for($user)->count(3)->create();

    $this->artisan('strava:hydrate-backlog')
        ->expectsOutputToContain('No summary-only runs left to hydrate.')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});

it('leaves un-ingested stubs to strava:ingest', function (): void {
    $user = drainUser();
    Activity::factory()->for($user)->stub()->count(3)->create();

    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('gives up on a run whose detail fetch has exhausted its attempts', function (): void {
    $user = drainUser();
    backlogRun($user)->update(['detail_fail_count' => Activity::MAX_DETAIL_FETCH_ATTEMPTS]);
    $drainable = backlogRun($user);
    $drainable->update(['detail_fail_count' => Activity::MAX_DETAIL_FETCH_ATTEMPTS - 1]);

    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => $job->activityId === $drainable->id,
    );
    Queue::assertPushed(IngestActivityJob::class, 1);
});

it('never spends a Strava read on the demo account', function (): void {
    backlogRun(drainUser(isDemo: true));

    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('skips users whose Strava connection is revoked', function (): void {
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->revoked()->create();
    backlogRun($user);

    $this->artisan('strava:hydrate-backlog')->assertSuccessful();

    Queue::assertNothingPushed();
});

it('splits the tick evenly so one deep archive cannot starve another user', function (): void {
    $deep = drainUser();
    $shallow = drainUser();
    for ($i = 0; $i < 6; $i++) {
        backlogRun($deep);
    }
    backlogRun($shallow);

    $this->artisan('strava:hydrate-backlog', ['--batch' => 4])->assertSuccessful();

    Queue::assertPushed(IngestActivityJob::class, 3);
    Queue::assertPushed(
        IngestActivityJob::class,
        fn (IngestActivityJob $job): bool => Activity::findOrFail($job->activityId)->user_id === $shallow->id,
    );
});

it('stops before the live-ingest reserve when the read pool is spent', function (): void {
    backlogRun(drainUser());

    $this->travelTo(now());
    for ($i = 0; $i < 150; $i++) {
        RateLimiter::hit('strava-api:15min', 900);
    }

    $this->artisan('strava:hydrate-backlog')
        ->expectsOutputToContain('Background read headroom is spent')
        ->assertSuccessful();

    Queue::assertNothingPushed();
});
