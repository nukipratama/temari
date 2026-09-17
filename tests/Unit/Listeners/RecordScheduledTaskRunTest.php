<?php

declare(strict_types=1);

use App\Enums\ScheduledTaskStatus;
use App\Listeners\RecordScheduledTaskRun;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

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
