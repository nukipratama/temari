<?php

declare(strict_types=1);

use App\Console\SchedulerChain;
use App\Enums\ScheduledTaskStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

function recordDailyAt(string $date, ScheduledTaskStatus $status): ScheduledTaskRun
{
    Carbon::setTestNow($date.' 18:00:00');

    return ScheduledTaskRun::record('race:remind', '0 18 * * *', $status);
}

it('reads a daily entry that keeps failing as late', function (): void {
    recordDailyAt('2026-10-01', ScheduledTaskStatus::Ok);
    foreach (['2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'] as $day) {
        recordDailyAt($day, ScheduledTaskStatus::Failed);
    }

    Carbon::setTestNow('2026-10-07 18:04:00');
    $run = ScheduledTaskRun::query()->where('command', 'race:remind')->sole();

    expect($run->last_status)->toBe(ScheduledTaskStatus::Failed)
        ->and(SchedulerChain::isLate('race:remind', $run))->toBeTrue();
});

it('reads a daily entry that failed once and then succeeded as on time', function (): void {
    recordDailyAt('2026-10-01', ScheduledTaskStatus::Ok);
    recordDailyAt('2026-10-05', ScheduledTaskStatus::Failed);
    recordDailyAt('2026-10-06', ScheduledTaskStatus::Ok);

    Carbon::setTestNow('2026-10-07 18:04:00');
    $run = ScheduledTaskRun::query()->where('command', 'race:remind')->sole();

    expect(SchedulerChain::isLate('race:remind', $run))->toBeFalse();
});

it('reads a daily entry whose runs were skipped as on time', function (): void {
    recordDailyAt('2026-10-05', ScheduledTaskStatus::Ok);
    recordDailyAt('2026-10-06', ScheduledTaskStatus::Skipped);
    recordDailyAt('2026-10-07', ScheduledTaskStatus::Skipped);

    Carbon::setTestNow('2026-10-08 18:04:00');
    $run = ScheduledTaskRun::query()->where('command', 'race:remind')->sole();

    expect(SchedulerChain::isLate('race:remind', $run))->toBeFalse();
});

it('reads a daily entry that has failed since its first record as late', function (): void {
    foreach (['2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04', '2026-10-05', '2026-10-06'] as $day) {
        recordDailyAt($day, ScheduledTaskStatus::Failed);
    }

    Carbon::setTestNow('2026-10-07 18:04:00');
    $run = ScheduledTaskRun::query()->where('command', 'race:remind')->sole();

    expect($run->last_success_at)->toBeNull()
        ->and(SchedulerChain::isLate('race:remind', $run))->toBeTrue();
});
