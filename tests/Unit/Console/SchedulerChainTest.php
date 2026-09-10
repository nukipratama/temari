<?php

declare(strict_types=1);

use App\Console\SchedulerChain;
use Illuminate\Support\Carbon;

beforeEach(fn () => Carbon::setTestNow('2026-09-14 00:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('is not done until marked done', function (): void {
    expect(SchedulerChain::isDoneToday(SchedulerChain::STREAK_SETTLE))->toBeFalse();
});

it('is done once marked done today', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::STREAK_SETTLE);

    expect(SchedulerChain::isDoneToday(SchedulerChain::STREAK_SETTLE))->toBeTrue();
});

it('keeps each command key independent', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);

    expect(SchedulerChain::isDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES))->toBeTrue()
        ->and(SchedulerChain::isDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE))->toBeFalse();
});

it('does not carry a done flag over to the next day', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::STREAK_SETTLE);

    Carbon::setTestNow('2026-09-21 00:00:00');

    expect(SchedulerChain::isDoneToday(SchedulerChain::STREAK_SETTLE))->toBeFalse();
});

it('names what each gated command waits for', function (): void {
    expect(SchedulerChain::prerequisitesFor('plan:regenerate'))
        ->toBe([SchedulerChain::PLAN_CLOSE_FINISHED_RACES, SchedulerChain::PLAN_SCORE_COMPLIANCE])
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
