<?php

declare(strict_types=1);

use App\Listeners\RecordScheduledTaskRun;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('records a finished command as ok with its cadence and runtime', function (): void {
    $task = app(Schedule::class)->command('strava:sync')->hourly();

    (new RecordScheduledTaskRun())->finished(new ScheduledTaskFinished($task, 1.5));

    $row = ScheduledTaskRun::query()->sole();
    expect($row->command)->toBe('strava:sync')
        ->and($row->expression)->toBe('0 * * * *')
        ->and($row->last_status)->toBe(ScheduledTaskRun::STATUS_OK)
        ->and($row->runtime_ms)->toBe(1500)
        ->and($row->failure_message)->toBeNull();
});

it('records a failed command with its exception message', function (): void {
    $task = app(Schedule::class)->command('ai:weekly-recap')->weeklyOn(1, '05:30');

    (new RecordScheduledTaskRun())->failed(new ScheduledTaskFailed($task, new RuntimeException('kaboom')));

    $row = ScheduledTaskRun::query()->sole();
    expect($row->command)->toBe('ai:weekly-recap')
        ->and($row->last_status)->toBe(ScheduledTaskRun::STATUS_FAILED)
        ->and($row->failure_message)->toBe('kaboom')
        ->and($row->runtime_ms)->toBeNull();
});
