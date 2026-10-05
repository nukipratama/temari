<?php

declare(strict_types=1);

use App\Console\SchedulerChain;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;

uses()->group('structure');

/**
 * The scheduled event for exactly this artisan command, matched on a word
 * boundary. Mirrors the helper in ScheduleMutexTest.
 */
function scheduledChainEvent(string $command): ?Event
{
    return collect(app(Schedule::class)->events())->first(
        fn (Event $e): bool => preg_match('/\\b'.preg_quote($command, '/').'(?:\\s|$)/', (string) $e->command) === 1,
    );
}

function runsAt(Event $event, string $at): bool
{
    Carbon::setTestNow($at);

    return $event->isDue(app()) && $event->filtersPass(app());
}

beforeEach(fn () => Carbon::setTestNow('2026-09-14 00:00:00')); // a Monday
afterEach(fn () => Carbon::setTestNow());

it('no longer gates ai:weekly-recap on streak settlement', function (): void {
    expect(runsAt(scheduledChainEvent('ai:weekly-recap'), '2026-09-14 00:16:00'))->toBeTrue();
});

it('does not let plan:regenerate pass its when() gate until both prerequisites finish today', function (): void {
    $closeFinishedRaces = scheduledChainEvent('plan:close-finished-races');
    $scoreCompliance = scheduledChainEvent('plan:score-compliance');
    $regenerate = scheduledChainEvent('plan:regenerate');

    expect($regenerate)->not->toBeNull()
        ->and($regenerate->filtersPass(app()))->toBeFalse();

    $closeFinishedRaces->finish(app(), 0);

    expect($regenerate->filtersPass(app()))->toBeFalse('plan:regenerate must still wait on plan:score-compliance');

    $scoreCompliance->finish(app(), 0);

    expect($regenerate->filtersPass(app()))->toBeTrue();
});

it('catches a missed 00:26 plan:regenerate up on a later Monday tick, exactly once that week', function (): void {
    $regenerate = scheduledChainEvent('plan:regenerate');
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);

    expect(runsAt($regenerate, '2026-09-14 01:26:00'))->toBeTrue();

    $regenerate->finish(app(), 0);

    expect(runsAt($regenerate, '2026-09-14 02:26:00'))->toBeFalse()
        ->and(runsAt($regenerate, '2026-09-15 00:26:00'))->toBeFalse();
});

it('keeps retrying plan:regenerate after a failed run', function (): void {
    $regenerate = scheduledChainEvent('plan:regenerate');
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_CLOSE_FINISHED_RACES);
    SchedulerChain::markDoneToday(SchedulerChain::PLAN_SCORE_COMPLIANCE);

    Carbon::setTestNow('2026-09-14 00:26:00');
    $regenerate->finish(app(), 1);

    expect(runsAt($regenerate, '2026-09-14 01:26:00'))->toBeTrue();
});

it('catches a missed plan prerequisite up on a later tick the same day, then holds it', function (string $command, int $minute): void {
    $event = scheduledChainEvent($command);
    $at = fn (int $hour): string => sprintf('2026-09-14 %02d:%02d:00', $hour, $minute);

    expect(runsAt($event, $at(3)))->toBeTrue();

    $event->finish(app(), 0);

    expect(runsAt($event, $at(4)))->toBeFalse()
        ->and(runsAt($event, sprintf('2026-09-15 00:%02d:00', $minute)))->toBeTrue();
})->with([
    'plan:close-finished-races' => ['plan:close-finished-races', 4],
    'plan:score-compliance' => ['plan:score-compliance', 9],
]);

it('runs streak:settle every hour of every day', function (): void {
    $settle = scheduledChainEvent('streak:settle');

    expect(runsAt($settle, '2026-09-14 00:00:00'))->toBeTrue()
        ->and(runsAt($settle, '2026-09-14 05:00:00'))->toBeTrue()
        ->and(runsAt($settle, '2026-09-16 13:00:00'))->toBeTrue();
});

it('checks the Monday entries once, at 06:00', function (): void {
    $check = scheduledChainEvent('schedule:monday-check');

    expect($check)->not->toBeNull()
        ->and(runsAt($check, '2026-09-14 06:00:00'))->toBeTrue()
        ->and(runsAt($check, '2026-09-14 07:00:00'))->toBeFalse()
        ->and(runsAt($check, '2026-09-15 06:00:00'))->toBeFalse();
});

it('no longer schedules the monthly recap at 05:45', function (): void {
    expect(scheduledChainEvent('ai:monthly-recap'))->toBeNull();
});
