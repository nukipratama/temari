<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Models\User;
use App\Support\Cooldown;

/**
 * Rate-limits {@see \App\Http\Controllers\PlanController::regenerate()}, which re-narrates the season.
 */
final readonly class PlanRegenerateCooldown
{
    public const int SECONDS = 3600;

    public function remaining(User $user): ?int
    {
        return $this->cooldown($user)->remaining();
    }

    public function start(User $user): void
    {
        $this->cooldown($user)->start();
    }

    private function cooldown(User $user): Cooldown
    {
        return new Cooldown("plan-regenerate:{$user->id}", self::SECONDS);
    }
}
