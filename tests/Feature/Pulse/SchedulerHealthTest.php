<?php

declare(strict_types=1);

use App\Console\SchedulerChain;
use App\Livewire\Pulse\SchedulerHealth;
use App\Models\ScheduledTaskRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-06-10 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('renders without error when no command has run yet', function (): void {
    Livewire::test(SchedulerHealth::class)
        ->assertOk()
        ->assertSee('Scheduler');
});

it('shows a healthy command with its last-run time and runtime', function (): void {
    ScheduledTaskRun::query()->create([
        'command' => 'strava:sync',
        'expression' => '0 * * * *',
        'last_status' => 'ok',
        'last_run_at' => Carbon::now()->subMinutes(10),
        'runtime_ms' => 1200,
    ]);

    Livewire::test(SchedulerHealth::class)
        ->assertOk()
        ->assertSee('strava:sync')
        ->assertSee('ok')
        ->assertSee('1.2s');
});

it('surfaces a failed command with its message', function (): void {
    ScheduledTaskRun::query()->create([
        'command' => 'ai:weekly-recap',
        'expression' => '30 5 * * 1',
        'last_status' => 'failed',
        'last_run_at' => Carbon::now()->subMinutes(5),
        'failure_message' => 'Azure timed out',
    ]);

    Livewire::test(SchedulerHealth::class)
        ->assertOk()
        ->assertSee('ai:weekly-recap')
        ->assertSee('failed')
        ->assertSee('Azure timed out');
});

it('flags a command that has missed its cadence as late', function (): void {
    ScheduledTaskRun::query()->create([
        'command' => 'strava:ingest',
        'expression' => '*/5 * * * *',
        'last_status' => 'ok',
        'last_run_at' => Carbon::now()->subHours(2),
    ]);

    Livewire::test(SchedulerHealth::class)
        ->assertOk()
        ->assertSee('strava:ingest')
        ->assertSee('late');
});

it('lists a registered command that has never run, in next-due order', function (): void {
    Livewire::test(SchedulerHealth::class)
        ->assertOk()
        ->assertSee('strava:sync')
        ->assertSee('never run')
        ->assertSee('next');
});

it('shows the Monday chain prerequisites as pending until they finish today', function (): void {
    Livewire::test(SchedulerHealth::class)
        ->assertOk()
        ->assertSee('waits for')
        ->assertSee('streak:settle pending');
});

it('marks a chain prerequisite as done once it has run today', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::STREAK_SETTLE);

    Livewire::test(SchedulerHealth::class)
        ->assertOk()
        ->assertSee('streak:settle done');
});

it('keeps a recorded command that is no longer on the schedule', function (): void {
    ScheduledTaskRun::query()->create([
        'command' => 'ai:retired-command',
        'expression' => '0 * * * *',
        'last_status' => 'ok',
        'last_run_at' => Carbon::now()->subDay(),
    ]);

    Livewire::test(SchedulerHealth::class)
        ->assertOk()
        ->assertSee('ai:retired-command')
        ->assertSee('off the schedule');
});
