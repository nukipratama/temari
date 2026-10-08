<?php

declare(strict_types=1);

use App\Enums\ScheduledTaskStatus;
use App\Models\ScheduledTaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('upserts a single heartbeat row keyed on command', function (): void {
    ScheduledTaskRun::record('strava:sync', '0 * * * *', ScheduledTaskStatus::Ok, 1200);
    ScheduledTaskRun::record('strava:sync', '0 * * * *', ScheduledTaskStatus::Failed, null, 'boom');

    expect(ScheduledTaskRun::query()->count())->toBe(1);

    $row = ScheduledTaskRun::query()->where('command', 'strava:sync')->sole();
    expect($row->last_status)->toBe(ScheduledTaskStatus::Failed)
        ->and($row->hasFailed())->toBeTrue()
        ->and($row->failure_message)->toBe('boom')
        ->and($row->last_run_at)->not->toBeNull();
});

it('flags a command as stale once it misses ~2x its cadence', function (): void {
    Carbon::setTestNow('2026-06-10 12:00:00');

    $recent = new ScheduledTaskRun(['command' => 'a', 'expression' => '0 * * * *', 'last_status' => 'ok']);
    $recent->last_success_at = Carbon::now()->subMinutes(30);
    expect($recent->isStale())->toBeFalse();

    $late = new ScheduledTaskRun(['command' => 'b', 'expression' => '0 * * * *', 'last_status' => 'ok']);
    $late->last_success_at = Carbon::now()->subHours(3);
    expect($late->isStale())->toBeTrue();

    Carbon::setTestNow();
});

it('never reports stale when the signal is missing or unparseable', function (): void {
    $noExpression = new ScheduledTaskRun(['command' => 'a', 'last_status' => 'ok']);
    $noExpression->last_success_at = Carbon::now()->subYears(1);
    expect($noExpression->isStale())->toBeFalse();

    $neverRan = new ScheduledTaskRun(['command' => 'b', 'expression' => '0 * * * *', 'last_status' => 'ok']);
    expect($neverRan->isStale())->toBeFalse();

    $garbage = new ScheduledTaskRun(['command' => 'c', 'expression' => 'not-a-cron', 'last_status' => 'ok']);
    $garbage->last_success_at = Carbon::now()->subYears(1);
    expect($garbage->isStale())->toBeFalse();
});

it('advances the last success only on a successful run', function (): void {
    Carbon::setTestNow('2026-10-06 18:00:00');
    ScheduledTaskRun::record('race:remind', '0 18 * * *', ScheduledTaskStatus::Ok);
    Carbon::setTestNow('2026-10-07 18:00:00');
    $row = ScheduledTaskRun::record('race:remind', '0 18 * * *', ScheduledTaskStatus::Failed, failureMessage: 'boom');
    Carbon::setTestNow();

    expect($row->last_run_at?->toDateTimeString())->toBe('2026-10-07 18:00:00')
        ->and($row->last_success_at?->toDateTimeString())->toBe('2026-10-06 18:00:00');
});

it('advances the last success on a skipped run', function (): void {
    Carbon::setTestNow('2026-10-07 18:00:00');
    $row = ScheduledTaskRun::record('race:remind', '0 18 * * *', ScheduledTaskStatus::Skipped);
    Carbon::setTestNow();

    expect($row->last_success_at?->toDateTimeString())->toBe('2026-10-07 18:00:00');
});

it('measures staleness from the last success, or from the first record before any success', function (): void {
    Carbon::setTestNow('2026-10-07 18:04:00');

    $failing = new ScheduledTaskRun(['command' => 'a', 'expression' => '0 18 * * *', 'last_status' => 'failed']);
    $failing->last_run_at = Carbon::parse('2026-10-07 18:00:00');
    $failing->last_success_at = Carbon::parse('2026-10-01 18:00:00');

    $neverSucceeded = new ScheduledTaskRun(['command' => 'b', 'expression' => '0 18 * * *', 'last_status' => 'failed']);
    $neverSucceeded->last_run_at = Carbon::parse('2026-10-07 18:00:00');
    $neverSucceeded->created_at = Carbon::parse('2026-10-01 18:00:00');

    $justRecorded = new ScheduledTaskRun(['command' => 'c', 'expression' => '0 18 * * *', 'last_status' => 'failed']);
    $justRecorded->last_run_at = Carbon::parse('2026-10-07 18:00:00');
    $justRecorded->created_at = Carbon::parse('2026-10-07 18:00:00');

    expect($failing->isStale())->toBeTrue()
        ->and($neverSucceeded->isStale())->toBeTrue()
        ->and($justRecorded->isStale())->toBeFalse();

    Carbon::setTestNow();
});
