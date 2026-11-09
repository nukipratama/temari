<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when an athlete's race is set, revised, moved, retired or deleted.
 */
final readonly class RaceGoalChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public int $userId)
    {
    }
}
