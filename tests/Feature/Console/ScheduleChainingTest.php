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

beforeEach(fn () => Carbon::setTestNow('2026-09-14 00:00:00')); // a Monday
afterEach(fn () => Carbon::setTestNow());

it('does not let ai:weekly-recap pass its when() gate before streak:settle finishes today', function (): void {
    $recap = scheduledChainEvent('ai:weekly-recap');

    expect($recap)->not->toBeNull()
        ->and($recap->filtersPass(app()))->toBeFalse();
});

it('lets ai:weekly-recap pass its when() gate once streak:settle succeeds today', function (): void {
    $settle = scheduledChainEvent('streak:settle');
    $recap = scheduledChainEvent('ai:weekly-recap');

    expect($settle)->not->toBeNull()
        ->and($recap)->not->toBeNull();

    $settle->finish(app(), 0);

    expect($recap->filtersPass(app()))->toBeTrue();
});

it('does not mark streak:settle done when it fails', function (): void {
    $settle = scheduledChainEvent('streak:settle');
    $recap = scheduledChainEvent('ai:weekly-recap');

    $settle->finish(app(), 1);

    expect($recap->filtersPass(app()))->toBeFalse();
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

it('resets the chain flag on a new day', function (): void {
    SchedulerChain::markDoneToday(SchedulerChain::STREAK_SETTLE);

    expect(SchedulerChain::isDoneToday(SchedulerChain::STREAK_SETTLE))->toBeTrue();

    Carbon::setTestNow('2026-09-21 00:16:00'); // the following Monday

    expect(SchedulerChain::isDoneToday(SchedulerChain::STREAK_SETTLE))->toBeFalse();
});
