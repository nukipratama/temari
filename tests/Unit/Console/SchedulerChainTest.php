<?php

declare(strict_types=1);

use App\Console\SchedulerChain;
use App\Models\ScheduledTaskRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

beforeEach(fn () => Carbon::setTestNow('2026-09-14 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('is not done until marked done', function (): void {
    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeFalse()
        ->and(SchedulerChain::isDoneThisWeek(SchedulerChain::PLAN_REGENERATE))->toBeFalse();
});

it('is done once marked done today', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);

    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeTrue();
});

it('keeps each command key independent', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);

    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES))->toBeTrue()
        ->and(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeFalse();
});

it('does not carry a done-today flag over to the next day', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);

    Carbon::setTestNow('2026-09-15 00:00:00');

    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeFalse();
});

it('keeps a done-this-week flag for the rest of the ISO week and drops it on the next Monday', function (): void {
    SchedulerChain::markDoneThisWeek(SchedulerChain::PLAN_REGENERATE);

    Carbon::setTestNow('2026-09-20 23:59:00');
    expect(SchedulerChain::isDoneThisWeek(SchedulerChain::PLAN_REGENERATE))->toBeTrue();

    Carbon::setTestNow('2026-09-21 00:00:00');
    expect(SchedulerChain::isDoneThisWeek(SchedulerChain::PLAN_REGENERATE))->toBeFalse();
});

it('keeps its flags off the evictable cache store, so a flush of it loses none', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);
    SchedulerChain::markDoneThisWeek(SchedulerChain::PLAN_REGENERATE);

    Cache::store()->flush();

    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeTrue()
        ->and(SchedulerChain::isDoneThisWeek(SchedulerChain::PLAN_REGENERATE))->toBeTrue()
        ->and(config('cache.stores.durable.connection'))->toBe('default');
});

it('names what each gated command waits for', function (): void {
    expect(SchedulerChain::prerequisitesFor('plan:regenerate'))
        ->toBe([SchedulerChain::PLAN_CLOSE_FINISHED_RACES, SchedulerChain::PLAN_SCORE_COMPLIANCE])
        ->and(SchedulerChain::prerequisitesFor('ai:weekly-recap'))->toBe([])
        ->and(SchedulerChain::prerequisitesFor('strava:sync'))->toBe([]);
});

it('holds a gated command until every prerequisite is done today', function (): void {
    expect(SchedulerChain::prerequisitesMet('plan:regenerate'))->toBeFalse();

    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);
    expect(SchedulerChain::prerequisitesMet('plan:regenerate'))->toBeFalse();

    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);
    expect(SchedulerChain::prerequisitesMet('plan:regenerate'))->toBeTrue();
});

it('lets an ungated command through', function (): void {
    expect(SchedulerChain::prerequisitesMet('strava:sync'))->toBeTrue();
});

it('reads a daily-gated command as late only once a whole day passed without a success', function (): void {
    Carbon::setTestNow('2026-09-14 00:04:00');
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);

    Carbon::setTestNow('2026-09-15 23:59:00');
    expect(SchedulerChain::isLate(SchedulerChain::PLAN_SCORE_COMPLIANCE, null))->toBeFalse();

    Carbon::setTestNow('2026-09-16 00:00:00');
    expect(SchedulerChain::isLate(SchedulerChain::PLAN_SCORE_COMPLIANCE, null))->toBeTrue();

    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);
    expect(SchedulerChain::isLate(SchedulerChain::PLAN_SCORE_COMPLIANCE, null))->toBeFalse();
});

it('reads a weekly-gated command as late only once a whole ISO week passed without a success', function (): void {
    Carbon::setTestNow('2026-09-14 00:26:00');
    SchedulerChain::markDoneThisWeek(SchedulerChain::PLAN_REGENERATE);

    Carbon::setTestNow('2026-09-27 23:59:00');
    expect(SchedulerChain::isLate(SchedulerChain::PLAN_REGENERATE, null))->toBeFalse();

    Carbon::setTestNow('2026-09-28 00:00:00');
    expect(SchedulerChain::isLate(SchedulerChain::PLAN_REGENERATE, null))->toBeTrue();
});

it('ignores a stale heartbeat on a gated command whose gate is merely closed', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);
    Carbon::setTestNow('2026-09-14 20:00:00');
    $run = new ScheduledTaskRun(['command' => 'plan:close-finished-races', 'expression' => '4 * * * *', 'last_run_at' => Carbon::parse('2026-09-14 00:04:00'), 'last_success_at' => Carbon::parse('2026-09-14 00:04:00')]);

    expect($run->isStale())->toBeTrue()
        ->and(SchedulerChain::isLate(SchedulerChain::PLAN_CLOSE_FINISHED_RACES, $run))->toBeFalse();
});

it('reads an ungated command as late from its heartbeat', function (): void {
    $stale = new ScheduledTaskRun(['command' => 'strava:sync', 'expression' => '0 * * * *', 'last_run_at' => Carbon::now()->subHours(3), 'last_success_at' => Carbon::now()->subHours(3)]);
    $fresh = new ScheduledTaskRun(['command' => 'strava:sync', 'expression' => '0 * * * *', 'last_run_at' => Carbon::now()->subMinutes(30), 'last_success_at' => Carbon::now()->subMinutes(30)]);

    expect(SchedulerChain::isLate('strava:sync', $stale))->toBeTrue()
        ->and(SchedulerChain::isLate('strava:sync', $fresh))->toBeFalse()
        ->and(SchedulerChain::isLate('strava:sync', null))->toBeFalse();
});
