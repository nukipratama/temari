<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\TrendDailySnapshot;
use App\Models\User;
use App\Jobs\Run\ReconcileScheduledTrendSnapshotsJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('queues durable recovery by default', function (): void {
    Bus::fake();
    $user = User::factory()->create();

    $this->artisan('trend:snapshot-daily')
        ->expectsOutputToContain('Queued durable trend snapshot recovery for 1 users.')
        ->assertSuccessful();

    Bus::assertDispatched(ReconcileScheduledTrendSnapshotsJob::class, fn (ReconcileScheduledTrendSnapshotsJob $job): bool => $job->userId === $user->id);
});

it('writes a snapshot row for every real user', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::yesterday()]);

    $this->artisan('trend:snapshot-daily', ['--days' => 7])
        ->expectsOutputToContain('Reconciled 7 closed trend snapshot days for 1 users.')
        ->assertSuccessful();

    expect(TrendDailySnapshot::query()
        ->where('user_id', $user->id)
        ->whereDate('snapshot_date', Carbon::yesterday())
        ->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('writes a row even for a user with no run today, so a rest week still grows history', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();

    $this->artisan('trend:snapshot-daily', ['--days' => 7])->assertSuccessful();

    $snap = TrendDailySnapshot::query()
        ->where('user_id', $user->id)
        ->whereDate('snapshot_date', Carbon::yesterday())
        ->sole();
    expect($snap->vdot)->toBeNull()
        ->and($snap->pace_variability_sec)->toBeNull();

    Carbon::setTestNow();
});

it('writes a snapshot row for the demo user too, since this is free local computation, not a billing call', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $demo = User::factory()->demo()->create();

    $this->artisan('trend:snapshot-daily', ['--days' => 7])
        ->expectsOutputToContain('Reconciled 7 closed trend snapshot days for 1 users.')
        ->assertSuccessful();

    expect(TrendDailySnapshot::query()
        ->where('user_id', $demo->id)
        ->whereDate('snapshot_date', Carbon::yesterday())
        ->exists())->toBeTrue();

    Carbon::setTestNow();
});

it('is idempotent when run twice the same day', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();

    $this->artisan('trend:snapshot-daily', ['--days' => 7])->assertSuccessful();
    $this->artisan('trend:snapshot-daily', ['--days' => 7])->assertSuccessful();

    expect(TrendDailySnapshot::query()->where('user_id', $user->id)->count())->toBe(7);

    Carbon::setTestNow();
});
