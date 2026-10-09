<?php

declare(strict_types=1);

use App\Console\SchedulerChain;
use App\Models\ScheduledTaskRun;
use App\Services\AI\MaintainerAlerter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-10-06 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('pages each late entry and reports every other one as on time', function (): void {
    $lastRunAt = Carbon::now()->subHours(3);
    ScheduledTaskRun::query()->create([
        'command' => 'strava:sync',
        'expression' => '0 * * * *',
        'last_status' => 'ok',
        'last_run_at' => $lastRunAt,
        'last_success_at' => $lastRunAt,
    ]);
    ScheduledTaskRun::query()->create([
        'command' => 'ai:self-heal',
        'expression' => '0 * * * *',
        'last_status' => 'ok',
        'last_run_at' => Carbon::now()->subMinutes(30),
        'last_success_at' => Carbon::now()->subMinutes(30),
    ]);
    foreach (SchedulerChain::DAILY_GATED as $command) {
        SchedulerChain::markDoneToday($command);
    }
    SchedulerChain::markDoneThisWeek(SchedulerChain::PLAN_REGENERATE);

    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('schedulerLate')->once()->withArgs(
        fn (string $command, ?ScheduledTaskRun $run): bool => $command === 'strava:sync' && $run?->last_run_at?->equalTo($lastRunAt) === true,
    );
    $alerter->shouldReceive('schedulerOnTime')->with('ai:self-heal')->once();
    $alerter->shouldReceive('schedulerOnTime')->with('plan:regenerate')->once();
    $alerter->shouldReceive('schedulerOnTime')->with(Mockery::type('string'));
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('schedule:check-late')
        ->expectsOutputToContain('Late: strava:sync')
        ->assertSuccessful();
});

it('pages a gated entry that has gone a whole week without a success', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);

    $alerter = Mockery::mock(MaintainerAlerter::class);
    $alerter->shouldReceive('schedulerLate')->once()->with('plan:regenerate', null);
    $alerter->shouldReceive('schedulerOnTime')->with(Mockery::type('string'));
    $this->app->instance(MaintainerAlerter::class, $alerter);

    $this->artisan('schedule:check-late')->assertSuccessful();
});
