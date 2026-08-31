<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Gamification\EquippedAccessories;
use App\Support\SharedPropCacheKey;
use Closure;

/**
 * The collection-and-progress family of shared props: what the mascot is
 * wearing, and the race being trained for.
 *
 * Every prop is returned as a closure, so Inertia skips the work entirely on a
 * partial reload that did not ask for that key.
 */
final readonly class GamificationProps
{
    public function __construct(
        private EquippedAccessories $equippedAccessories,
    ) {
    }

    /**
     * @return array<string, Closure>
     */
    public function forUser(?User $user): array
    {
        return [
            'equippedAccessories' => fn (): array => $this->equippedAccessoriesFor($user),
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

    /**
     * Which accessories the mascot is wearing. Cached because it costs a
     * `user_unlocks` scan on every page load while only ever moving when the
     * user equips something ({@see \App\Http\Controllers\AccessoryController}
     * busts it there). Granting an unlock cannot change it: rows are inserted
     * without `equipped`, which defaults to false.
     *
     * @return array<string, string|null>
     */
    private function equippedAccessoriesFor(?User $user): array
    {
        if ($user === null) {
            return $this->equippedAccessories->forUser(null);
        }

        return SharedPropCacheKey::EquippedAccessories->remember(
            $user->id,
            fn (): array => $this->equippedAccessories->forUser($user),
        );
    }
}
