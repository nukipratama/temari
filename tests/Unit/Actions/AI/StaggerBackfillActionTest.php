<?php

declare(strict_types=1);

use App\Actions\AI\StaggerBackfillAction;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

function staggerBackfill(): StaggerBackfillAction
{
    return new StaggerBackfillAction();
}

it('reserves an immediate slot (delay 0) for a user with no prior reservation', function (): void {
    expect(staggerBackfill()(1))->toBe(0);
});

it('staggers a second reservation for the same user by the configured window', function (): void {
    config(['ai.backfill_stagger_seconds' => 100]);
    Carbon::setTestNow('2026-05-18 12:00:00');
    $stagger = staggerBackfill();

    expect($stagger(1))->toBe(0)
        ->and($stagger(1))->toBe(100)
        ->and($stagger(1))->toBe(200);

    Carbon::setTestNow();
});

it('keeps each user\'s slot independent', function (): void {
    config(['ai.backfill_stagger_seconds' => 100]);
    Carbon::setTestNow('2026-05-18 12:00:00');
    $stagger = staggerBackfill();

    expect($stagger(1))->toBe(0)
        ->and($stagger(2))->toBe(0)
        ->and($stagger(1))->toBe(100)
        ->and($stagger(2))->toBe(100);

    Carbon::setTestNow();
});

it('resets to an immediate slot once the previously reserved slot is in the past', function (): void {
    config(['ai.backfill_stagger_seconds' => 100]);
    Carbon::setTestNow('2026-05-18 12:00:00');
    $stagger = staggerBackfill();
    expect($stagger(1))->toBe(0);

    // Jump well past the reserved slot (12:00:00 + 100s).
    Carbon::setTestNow('2026-05-18 12:10:00');
    expect($stagger(1))->toBe(0);

    Carbon::setTestNow();
});

it('floors the stagger window at 1 second even if misconfigured to 0 or negative', function (): void {
    config(['ai.backfill_stagger_seconds' => 0]);
    Carbon::setTestNow('2026-05-18 12:00:00');
    $stagger = staggerBackfill();

    expect($stagger(1))->toBe(0)
        ->and($stagger(1))->toBe(1);

    Carbon::setTestNow();
});

it('falls back to delay 0 on a lock timeout rather than blocking the caller', function (): void {
    Cache::shouldReceive('lock')
        ->once()
        ->andThrow(new LockTimeoutException());

    expect(staggerBackfill()(1))->toBe(0);
});
