<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\RaceSupport;
use App\Models\RaceGoal;
use App\Models\RaceGoalChange;
use App\Models\User;

final readonly class RacePresenter
{
    public function __construct(private RaceAmbitionAssessor $ambition)
    {
    }

    /**
     * @return array{id: int, race_date: string, distance_m: int, goal_time_sec: int, name: string|null, ambition: array<string, mixed>, support: array{mode: string, dedicated_preparation: bool, limitation: string|null}, history: list<array{kind: string, race_date: string|null, goal_time_sec: int|null, recorded_at: string}>}
     */
    public function present(User $user, RaceGoal $race): array
    {
        $support = RaceSupport::forDistance((float) $race->distance_m);

        return [
            'id' => $race->id,
            'race_date' => $race->race_date->toDateString(),
            'distance_m' => $race->distance_m,
            'goal_time_sec' => $race->goal_time_sec,
            'name' => $race->name,
            'ambition' => $this->ambition->assess($user, $race)->toArray(),
            'support' => [
                'mode' => $support->value,
                'dedicated_preparation' => $support->dedicatedPreparation(),
                'limitation' => $support->limitation(),
            ],
            'history' => array_values($race->changes->map(static fn (RaceGoalChange $change): array => [
                'kind' => $change->kind->value,
                'race_date' => $change->race_date?->toDateString(),
                'goal_time_sec' => $change->goal_time_sec,
                'recorded_at' => $change->created_at->toIso8601String(),
            ])->all()),
        ];
    }
}
