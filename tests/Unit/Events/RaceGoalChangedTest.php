<?php

declare(strict_types=1);

use App\Events\RaceGoalChanged;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

it('carries the athlete id and waits for the race write to commit', function (): void {
    expect(new RaceGoalChanged(42)->userId)->toBe(42)
        ->and(new RaceGoalChanged(42))->toBeInstanceOf(ShouldDispatchAfterCommit::class);
});
