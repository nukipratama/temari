<?php

declare(strict_types=1);

use App\Enums\ScheduledTaskSkipReason;
use App\Enums\ScheduledTaskStatus;
use App\Models\Analytics\ScheduledTaskRunLog;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-06 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function runLog(string $command, ScheduledTaskStatus $status, ?int $runtimeMs, string $startedAgo = '1 hour'): ScheduledTaskRunLog
{
    return ScheduledTaskRunLog::query()->create([
        'command' => $command,
        'started_at' => Carbon::now()->sub($startedAgo),
        'finished_at' => $status === ScheduledTaskStatus::Running ? null : Carbon::now()->sub($startedAgo),
        'runtime_ms' => $runtimeMs,
        'status' => $status,
        'skipped_reason' => $status === ScheduledTaskStatus::Skipped ? ScheduledTaskSkipReason::Gate : null,
    ]);
}

it('uses analytics and casts its columns', function (): void {
    $log = ScheduledTaskRunLog::start('strava:sync');
    $log->close(ScheduledTaskStatus::Failed, 1, 1500);

    $stored = ScheduledTaskRunLog::query()->findOrFail($log->id);

    expect($stored->getConnectionName())->toBe('analytics')
        ->and($stored->id)->toBeInt()
        ->and($stored->started_at)->toBeInstanceOf(Carbon::class)
        ->and($stored->finished_at)->toBeInstanceOf(Carbon::class)
        ->and($stored->status)->toBe(ScheduledTaskStatus::Failed)
        ->and($stored->exit_code)->toBe(1)
        ->and($stored->runtime_ms)->toBe(1500)
        ->and($stored->skipped_reason)->toBeNull();
});

it('opens a running row with no finish', function (): void {
    $log = ScheduledTaskRunLog::start('strava:sync');

    expect($log->status)->toBe(ScheduledTaskStatus::Running)
        ->and($log->started_at->equalTo(Carbon::now()))->toBeTrue()
        ->and($log->finished_at)->toBeNull();
});

it('measures the runtime from its start when none is given', function (): void {
    $log = ScheduledTaskRunLog::start('strava:sync');
    Carbon::setTestNow(Carbon::now()->addSeconds(3));

    $log->close(ScheduledTaskStatus::Failed, null, null);

    expect($log->fresh()?->runtime_ms)->toBe(3000);
});

it('records a gate skip as a closed row with its reason', function (): void {
    $log = ScheduledTaskRunLog::skip('plan:regenerate', ScheduledTaskSkipReason::Gate);

    expect($log->fresh()?->status)->toBe(ScheduledTaskStatus::Skipped)
        ->and($log->fresh()?->skipped_reason)->toBe(ScheduledTaskSkipReason::Gate)
        ->and($log->fresh()?->finished_at)->not->toBeNull();
});

it('turns an opened run into an overlap skip', function (): void {
    $log = ScheduledTaskRunLog::start('strava:sync');

    $log->skipOverlapping();

    expect($log->fresh()?->status)->toBe(ScheduledTaskStatus::Skipped)
        ->and($log->fresh()?->skipped_reason)->toBe(ScheduledTaskSkipReason::Overlapping)
        ->and($log->fresh()?->runtime_ms)->toBeNull();
});

it('sizes the killed window from the overlap lock', function (): void {
    $event = app(Schedule::class)->command('strava:sync')->hourly()->withoutOverlapping(55);

    expect(ScheduledTaskRunLog::windowSeconds($event))->toBe(3300);
});

it('counts runs, failures and skips and takes nearest-rank runtime percentiles of ok runs', function (): void {
    foreach (range(1, 20) as $ms) {
        runLog('strava:sync', ScheduledTaskStatus::Ok, $ms * 100);
    }
    runLog('strava:sync', ScheduledTaskStatus::Failed, 99_999);
    runLog('strava:sync', ScheduledTaskStatus::Skipped, null);
    runLog('strava:sync', ScheduledTaskStatus::Skipped, null);

    expect(ScheduledTaskRunLog::stats(['strava:sync' => 3300])['strava:sync'])->toBe([
        'runs' => 21,
        'failures' => 1,
        'skips' => 2,
        'killed' => 0,
        'p50' => 1000,
        'p95' => 1900,
        'max' => 2000,
        'latestKilled' => false,
    ]);
});

it('only reads the last 30 days', function (): void {
    runLog('strava:sync', ScheduledTaskStatus::Ok, 100, '31 days');

    expect(ScheduledTaskRunLog::stats([]))->toBe([]);
});

it('reads a run open past its window as killed and in progress within it', function (): void {
    runLog('strava:sync', ScheduledTaskStatus::Ok, 100, '3 hours');
    runLog('strava:sync', ScheduledTaskStatus::Running, null, '2 hours');
    runLog('ai:self-heal', ScheduledTaskStatus::Running, null, '10 minutes');

    $stats = ScheduledTaskRunLog::stats(['strava:sync' => 3300, 'ai:self-heal' => 3300]);

    expect($stats['strava:sync']['killed'])->toBe(1)
        ->and($stats['strava:sync']['latestKilled'])->toBeTrue()
        ->and($stats['strava:sync']['runs'])->toBe(2)
        ->and($stats['ai:self-heal']['killed'])->toBe(0)
        ->and($stats['ai:self-heal']['latestKilled'])->toBeFalse()
        ->and($stats['ai:self-heal']['p50'])->toBeNull();
});

it('no longer reads a killed run as the latest once a later run starts', function (): void {
    runLog('strava:sync', ScheduledTaskStatus::Running, null, '3 hours');
    runLog('strava:sync', ScheduledTaskStatus::Ok, 100, '1 hour');

    $stats = ScheduledTaskRunLog::stats(['strava:sync' => 3300]);

    expect($stats['strava:sync']['killed'])->toBe(1)
        ->and($stats['strava:sync']['latestKilled'])->toBeFalse();
});

it('falls back to a day-long window for a command off the schedule', function (): void {
    runLog('ai:retired', ScheduledTaskStatus::Running, null, '23 hours');
    runLog('ai:older', ScheduledTaskStatus::Running, null, '25 hours');

    $stats = ScheduledTaskRunLog::stats(['strava:sync' => 3300]);

    expect($stats['ai:retired']['killed'])->toBe(0)
        ->and($stats['ai:older']['killed'])->toBe(1);
});
