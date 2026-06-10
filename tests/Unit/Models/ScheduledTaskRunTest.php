<?php

declare(strict_types=1);

use App\Models\ScheduledTaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('upserts a single heartbeat row keyed on command', function (): void {
    ScheduledTaskRun::record('strava:sync', '0 * * * *', ScheduledTaskRun::STATUS_OK, 1200);
    ScheduledTaskRun::record('strava:sync', '0 * * * *', ScheduledTaskRun::STATUS_FAILED, null, 'boom');

    expect(ScheduledTaskRun::query()->count())->toBe(1);

    $row = ScheduledTaskRun::query()->where('command', 'strava:sync')->sole();
    expect($row->last_status)->toBe(ScheduledTaskRun::STATUS_FAILED)
        ->and($row->hasFailed())->toBeTrue()
        ->and($row->failure_message)->toBe('boom')
        ->and($row->last_run_at)->not->toBeNull();
});

it('flags a command as stale once it misses ~2x its cadence', function (): void {
    Carbon::setTestNow('2026-06-10 12:00:00');

    $recent = new ScheduledTaskRun(['command' => 'a', 'expression' => '0 * * * *', 'last_status' => 'ok']);
    $recent->last_run_at = Carbon::now()->subMinutes(30);
    expect($recent->isStale())->toBeFalse();

    $late = new ScheduledTaskRun(['command' => 'b', 'expression' => '0 * * * *', 'last_status' => 'ok']);
    $late->last_run_at = Carbon::now()->subHours(3);
    expect($late->isStale())->toBeTrue();

    Carbon::setTestNow();
});

it('never reports stale when the signal is missing or unparseable', function (): void {
    $noExpression = new ScheduledTaskRun(['command' => 'a', 'last_status' => 'ok']);
    $noExpression->last_run_at = Carbon::now()->subYears(1);
    expect($noExpression->isStale())->toBeFalse();

    $neverRan = new ScheduledTaskRun(['command' => 'b', 'expression' => '0 * * * *', 'last_status' => 'ok']);
    expect($neverRan->isStale())->toBeFalse();

    $garbage = new ScheduledTaskRun(['command' => 'c', 'expression' => 'not-a-cron', 'last_status' => 'ok']);
    $garbage->last_run_at = Carbon::now()->subYears(1);
    expect($garbage->isStale())->toBeFalse();
});
