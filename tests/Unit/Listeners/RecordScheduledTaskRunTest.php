<?php

declare(strict_types=1);

use App\Enums\ScheduledTaskSkipReason;
use App\Enums\ScheduledTaskStatus;
use App\Listeners\RecordScheduledTaskRun;
use App\Models\Analytics\ScheduledTaskRunLog;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

uses(RefreshDatabase::class);

it('records a finished command as ok with its cadence and runtime', function (): void {
    $task = app(Schedule::class)->command('strava:sync')->hourly();

    new RecordScheduledTaskRun()->finished(new ScheduledTaskFinished($task, 1.5));

    $row = ScheduledTaskRun::query()->sole();
    expect($row->command)->toBe('strava:sync')
        ->and($row->expression)->toBe('0 * * * *')
        ->and($row->last_status)->toBe(ScheduledTaskStatus::Ok)
        ->and($row->runtime_ms)->toBe(1500)
        ->and($row->failure_message)->toBeNull();
});

it('records a failed command with its exception message', function (): void {
    $task = app(Schedule::class)->command('ai:weekly-recap')->weeklyOn(1, '05:30');

    new RecordScheduledTaskRun()->failed(new ScheduledTaskFailed($task, new RuntimeException('kaboom')));

    $row = ScheduledTaskRun::query()->sole();
    expect($row->command)->toBe('ai:weekly-recap')
        ->and($row->last_status)->toBe(ScheduledTaskStatus::Failed)
        ->and($row->failure_message)->toBe('kaboom')
        ->and($row->runtime_ms)->toBeNull();
});

it('keeps the distinguishing argument so same-signature ranges stay attributable', function (): void {
    $sevenDay = app(Schedule::class)->command('ai:trend-read 7d')->dailyAt('06:00');
    $thirtyDay = app(Schedule::class)->command('ai:trend-read 30d')->dailyAt('06:00');

    new RecordScheduledTaskRun()->finished(new ScheduledTaskFinished($sevenDay, 1.0));
    new RecordScheduledTaskRun()->finished(new ScheduledTaskFinished($thirtyDay, 1.0));

    expect(ScheduledTaskRun::query()->pluck('command')->sort()->values()->all())
        ->toBe(['ai:trend-read 30d', 'ai:trend-read 7d']);
});

it('falls back to getSummaryForDisplay for a closure-based scheduled event (no artisan command to regex-match)', function (): void {
    $task = app(Schedule::class)->call(fn () => null)->hourly();

    new RecordScheduledTaskRun()->finished(new ScheduledTaskFinished($task, 1.0));

    $row = ScheduledTaskRun::query()->sole();
    expect($row->command)->toBe($task->getSummaryForDisplay());
});

it('opens a run log row when a command starts and closes it ok when it finishes', function (): void {
    $task = app(Schedule::class)->command('strava:sync')->hourly();
    $listener = new RecordScheduledTaskRun();

    $listener->starting(new ScheduledTaskStarting($task));
    expect(ScheduledTaskRunLog::query()->sole()->status)->toBe(ScheduledTaskStatus::Running);

    $task->exitCode = 0;
    $listener->finished(new ScheduledTaskFinished($task, 2.25));

    $log = ScheduledTaskRunLog::query()->sole();
    expect($log->command)->toBe('strava:sync')
        ->and($log->status)->toBe(ScheduledTaskStatus::Ok)
        ->and($log->exit_code)->toBe(0)
        ->and($log->runtime_ms)->toBe(2250)
        ->and($log->finished_at)->not->toBeNull();
});

it('closes a non-zero exit as failed once, leaving an older open row alone', function (): void {
    $task = app(Schedule::class)->command('strava:sync')->hourly();
    $killed = ScheduledTaskRunLog::start('strava:sync');
    $listener = new RecordScheduledTaskRun();

    $listener->starting(new ScheduledTaskStarting($task));
    $task->exitCode = 2;
    $listener->finished(new ScheduledTaskFinished($task, 1.0));
    $listener->failed(new ScheduledTaskFailed($task, new RuntimeException('exit 2')));

    $latest = ScheduledTaskRunLog::query()->latest('id')->firstOrFail();
    expect($latest->status)->toBe(ScheduledTaskStatus::Failed)
        ->and($latest->exit_code)->toBe(2)
        ->and($killed->fresh()?->status)->toBe(ScheduledTaskStatus::Running);
});

it('closes a run that threw as failed, timed from its start', function (): void {
    Carbon::setTestNow('2026-10-06 12:00:00');
    $task = app(Schedule::class)->command('strava:sync')->hourly();
    $listener = new RecordScheduledTaskRun();

    $listener->starting(new ScheduledTaskStarting($task));
    Carbon::setTestNow('2026-10-06 12:00:04');
    $listener->failed(new ScheduledTaskFailed($task, new RuntimeException('kaboom')));
    Carbon::setTestNow();

    $log = ScheduledTaskRunLog::query()->sole();
    expect($log->status)->toBe(ScheduledTaskStatus::Failed)
        ->and($log->runtime_ms)->toBe(4000);
});

it('records a run that found its overlap lock taken as an overlap skip', function (): void {
    $task = app(Schedule::class)->command('strava:sync')->hourly()->withoutOverlapping(55);
    $listener = new RecordScheduledTaskRun();

    $listener->starting(new ScheduledTaskStarting($task));
    $task->skippedBecauseOverlapping = true;
    $listener->finished(new ScheduledTaskFinished($task, 0.0));

    $log = ScheduledTaskRunLog::query()->sole();
    expect($log->status)->toBe(ScheduledTaskStatus::Skipped)
        ->and($log->skipped_reason)->toBe(ScheduledTaskSkipReason::Overlapping);
});

it('leaves the heartbeat untouched when the overlap lock was taken, so a jam goes late', function (): void {
    $task = app(Schedule::class)->command('strava:sync')->hourly()->withoutOverlapping(55);
    $lastRunAt = Carbon::parse('2026-10-06 09:00:00');
    ScheduledTaskRun::query()->create([
        'command' => 'strava:sync',
        'expression' => '0 * * * *',
        'last_status' => 'ok',
        'last_run_at' => $lastRunAt,
        'runtime_ms' => 900,
    ]);
    $listener = new RecordScheduledTaskRun();

    $listener->starting(new ScheduledTaskStarting($task));
    $task->skippedBecauseOverlapping = true;
    $listener->finished(new ScheduledTaskFinished($task, 0.0));

    $row = ScheduledTaskRun::query()->sole();
    expect($row->last_run_at?->equalTo($lastRunAt))->toBeTrue()
        ->and($row->runtime_ms)->toBe(900);
});

it('records a closed gate as a gate skip', function (): void {
    $task = app(Schedule::class)->command('plan:regenerate')->hourly()->when(fn (): bool => false);

    new RecordScheduledTaskRun()->skipped(new ScheduledTaskSkipped($task));

    $log = ScheduledTaskRunLog::query()->sole();
    expect($log->command)->toBe('plan:regenerate')
        ->and($log->status)->toBe(ScheduledTaskStatus::Skipped)
        ->and($log->skipped_reason)->toBe(ScheduledTaskSkipReason::Gate);
});

it('records a skip while the schedule is paused as a pause skip', function (): void {
    Cache::put('illuminate:schedule:paused', true);
    $task = app(Schedule::class)->command('strava:sync')->hourly();

    new RecordScheduledTaskRun()->skipped(new ScheduledTaskSkipped($task));

    expect(ScheduledTaskRunLog::query()->sole()->skipped_reason)->toBe(ScheduledTaskSkipReason::Paused);
});

it('keeps the heartbeat out of the run log', function (): void {
    $task = app(Schedule::class)->command('schedule:heartbeat')->everyMinute();
    $listener = new RecordScheduledTaskRun();

    $listener->starting(new ScheduledTaskStarting($task));
    $listener->finished(new ScheduledTaskFinished($task, 0.1));
    $listener->skipped(new ScheduledTaskSkipped($task));

    expect(ScheduledTaskRunLog::query()->count())->toBe(0)
        ->and(ScheduledTaskRun::query()->sole()->command)->toBe('schedule:heartbeat');
});

it('is bound as a singleton so the instance that opens a run closes it', function (): void {
    expect(app(RecordScheduledTaskRun::class))->toBe(app(RecordScheduledTaskRun::class));
});

it('reports a run log write that throws without stopping the run or failing it', function (): void {
    Exceptions::fake();
    ScheduledTaskRunLog::creating(fn (): never => throw new RuntimeException('analytics down'));
    $task = app(Schedule::class)->command('strava:sync')->hourly();
    $listener = new RecordScheduledTaskRun();

    $listener->starting(new ScheduledTaskStarting($task));
    $listener->skipped(new ScheduledTaskSkipped($task));
    $listener->finished(new ScheduledTaskFinished($task, 1.0));

    expect(ScheduledTaskRun::query()->sole()->last_status)->toBe(ScheduledTaskStatus::Ok);
    Exceptions::assertReportedCount(2);
});

it('keeps the last success when a command exits non-zero', function (): void {
    $task = app(Schedule::class)->command('race:remind')->dailyAt('18:00');
    $lastSuccessAt = Carbon::parse('2026-10-06 18:00:00');
    ScheduledTaskRun::query()->create([
        'command' => 'race:remind',
        'expression' => '0 18 * * *',
        'last_status' => 'ok',
        'last_run_at' => $lastSuccessAt,
        'last_success_at' => $lastSuccessAt,
    ]);
    $listener = new RecordScheduledTaskRun();

    $task->exitCode = 1;
    $listener->finished(new ScheduledTaskFinished($task, 1.0));
    $listener->failed(new ScheduledTaskFailed($task, new RuntimeException('exit 1')));

    $row = ScheduledTaskRun::query()->sole();
    expect($row->last_status)->toBe(ScheduledTaskStatus::Failed)
        ->and($row->last_success_at?->equalTo($lastSuccessAt))->toBeTrue();
});
