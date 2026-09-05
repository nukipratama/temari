<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\RaceGoal;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Closure;

/**
 * The collection-and-progress family of shared props: currently just the race
 * being trained for. The equipped-accessories prop lived here too until `W2`
 * swept it along with the wardrobe surface `PP2` cut.
 *
 * Every prop is returned as a closure, so Inertia skips the work entirely on a
 * partial reload that did not ask for that key.
 */
final readonly class GamificationProps
{
    /**
     * @return array<string, Closure>
     */
    public function forUser(?User $user): array
    {
        return [
            'activeRace' => fn () => $this->activeRaceFor($user),
        ];
    }

    /**
     * The race the user is currently training for, shared app-wide. Kept
     * deliberately thin (no Riegel projection) — the projection is only
     * computed on the Race page itself, not on every page load.
     *
     * @return array{id: int, race_date: string, distance_m: int, goal_time_sec: int, name: string|null}|null
     */
    private function activeRaceFor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return SharedPropCacheKey::ActiveRace->remember(
            $user->id,
            function () use ($user): ?array {
                $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();

                return $race === null ? null : [
                    'id' => $race->id,
                    'race_date' => $race->race_date->toDateString(),
                    'distance_m' => $race->distance_m,
                    'goal_time_sec' => $race->goal_time_sec,
                    'name' => $race->name,
                ];
            },
        );
    }
}
