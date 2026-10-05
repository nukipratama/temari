<?php

declare(strict_types=1);

use App\Console\SchedulerChain;
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
