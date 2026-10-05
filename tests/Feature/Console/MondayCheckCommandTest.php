<?php

declare(strict_types=1);

use App\Console\SchedulerChain;
use App\Jobs\AI\SendMaintainerAlertJob;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-09-14 06:00:00');
    config(['services.telegram.bot_token' => 'test-token']);
    Bus::fake([SendMaintainerAlertJob::class]);
});

afterEach(fn () => Carbon::setTestNow());

function mondayChainDone(): void
{
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);
    SchedulerChain::markDoneThisWeek(SchedulerChain::PLAN_REGENERATE);
}

function athleteSettledThrough(?string $through): User
{
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => '2026-09-13', 'runs' => 2]);
    $user->forceFill(['streak_settled_through' => $through])->saveQuietly();

    return $user;
}

it('stays quiet when every Monday entry succeeded and every athlete is settled', function (): void {
    mondayChainDone();
    athleteSettledThrough('2026-09-13');

    $this->artisan('schedule:monday-check')
        ->expectsOutputToContain('Every Monday entry has succeeded.')
        ->assertSuccessful();

    Bus::assertNotDispatched(SendMaintainerAlertJob::class);
});

it('alerts once when an athlete is still unsettled six hours after the week closed, and no more', function (): void {
    mondayChainDone();
    athleteSettledThrough('2026-09-13');
    athleteSettledThrough('2026-09-06');

    $this->artisan('schedule:monday-check')->assertSuccessful();
    $this->artisan('schedule:monday-check')->assertSuccessful();

    Bus::assertDispatchedTimes(SendMaintainerAlertJob::class, 1);
    Bus::assertDispatched(fn (SendMaintainerAlertJob $job): bool => str_contains($job->message, 'streak:settle (1 athlete unsettled)'));
});

it('names every plan entry that has not succeeded yet', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);

    $this->artisan('schedule:monday-check')
        ->expectsOutputToContain('plan:score-compliance, plan:regenerate')
        ->assertSuccessful();

    Bus::assertDispatched(fn (SendMaintainerAlertJob $job): bool => str_contains($job->message, 'plan:score-compliance, plan:regenerate')
        && ! str_contains($job->message, 'plan:close-finished-races'));
});
