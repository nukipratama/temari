<?php

declare(strict_types=1);

namespace App\Events;

use App\Enums\PlanRegenerationReason;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Support\Carbon;

/**
 * Fired when an athlete's plan has just been regenerated, so the season
 * narration can follow it without the plan code knowing how it is requested.
 */
final readonly class PlanRegenerated
{
    use Dispatchable;

    public function __construct(
        public User $user,
        public Carbon $today,
        public PlanRegenerationReason $reason,
    ) {
    }
}
