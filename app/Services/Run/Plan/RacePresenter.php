<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\RaceOutcome;
use App\Enums\RaceSupport;
use App\Models\RaceGoal;
use App\Models\RaceGoalChange;
use App\Models\User;

/**
 * The stable race payload the Race page and later surfaces read.
 *
 * @phpstan-import-type Candidate from RaceOutcomeMatcher
 */
final readonly class RacePresenter
{
    public function __construct(
        private RaceAmbitionAssessor $ambition,
        private RaceOutcomeMatcher $matcher,
    ) {
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

    /**
     * A race that has passed, with its outcome and, while there is no confirmed result, the run to offer.
     *
     * @return array{id: int, race_date: string, distance_m: int, goal_time_sec: int, name: string|null, outcome: array{state: string, finish_time_sec: int|null, activity_id: int|null, recorded_at: string|null, suggestion: Candidate|null}}
     */
    public function presentPast(RaceGoal $race): array
    {
        $outcome = $race->outcome ?? RaceOutcome::Pending;

        return [
            'id' => $race->id,
            'race_date' => $race->race_date->toDateString(),
            'distance_m' => $race->distance_m,
            'goal_time_sec' => $race->goal_time_sec,
            'name' => $race->name,
            'outcome' => [
                'state' => $outcome->value,
                'finish_time_sec' => $race->finish_time_sec,
                'activity_id' => $race->outcome_activity_id,
                'recorded_at' => $race->outcome_recorded_at?->toIso8601String(),
                'suggestion' => $outcome === RaceOutcome::Confirmed ? null : $this->matcher->candidates($race)->first(),
            ],
        ];
    }
}
