<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Actions\Run\Plan\ResolveActiveRaceAction;
use App\Enums\RaceChangeKind;
use App\Enums\RaceIntent;
use App\Enums\RaceOutcome;
use App\Models\RaceGoal;
use App\Models\RaceGoalChange;
use App\Models\User;
use App\Support\SharedPropCacheKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one write path for a race event's lifecycle. A race row is the event: revising its target or
 * date keeps the row (and so its season), while a new event or a cancellation retires it.
 *
 * @phpstan-type RaceAttributes array{race_date: string, distance_m: int, goal_time_sec: int, name?: string|null}
 */
final readonly class RaceGoalService
{
    public function __construct(private ResolveActiveRaceAction $activeRace)
    {
    }

    /**
     * @param  RaceAttributes  $attributes
     */
    public function submit(User $user, array $attributes, RaceIntent $intent): RaceGoal
    {
        $race = DB::transaction(function () use ($user, $attributes, $intent): RaceGoal {
            $active = $this->lockedActive($user);

            return match (true) {
                $active === null => $this->create($user, $attributes),
                $intent === RaceIntent::Update => $this->revise($active, $attributes),
                default => $this->replace($active, $attributes),
            };
        });
        $this->forget($user);

        return $race;
    }

    /**
     * @param  RaceAttributes  $attributes
     */
    public function createUnlessActive(User $user, array $attributes): RaceGoal
    {
        $race = DB::transaction(fn (): RaceGoal => $this->lockedActive($user) ?? $this->create($user, $attributes));
        $this->forget($user);

        return $race;
    }

    public function cancel(User $user): ?RaceGoal
    {
        $race = DB::transaction(function () use ($user): ?RaceGoal {
            $active = $this->lockedActive($user);
            if ($active === null) {
                return null;
            }

            $active->update([
                'completed_at' => now(),
                'outcome' => RaceOutcome::Cancelled,
                'outcome_recorded_at' => now(),
            ]);
            $this->record($active, RaceChangeKind::Cancelled);

            return $active;
        });
        $this->forget($user);

        return $race;
    }

    private function lockedActive(User $user): ?RaceGoal
    {
        User::query()->whereKey($user->id)->lockForUpdate()->first();

        return RaceGoal::query()->where('user_id', $user->id)->active()->lockForUpdate()->first();
    }

    /**
     * @param  RaceAttributes  $attributes
     */
    private function create(User $user, array $attributes): RaceGoal
    {
        $race = RaceGoal::query()->create([
            'user_id' => $user->id,
            'race_date' => $attributes['race_date'],
            'distance_m' => $attributes['distance_m'],
            'goal_time_sec' => $attributes['goal_time_sec'],
            'name' => $attributes['name'] ?? null,
            'outcome' => RaceOutcome::Pending,
        ]);
        $this->record($race, RaceChangeKind::Created);

        return $race;
    }

    /**
     * @param  RaceAttributes  $attributes
     */
    private function revise(RaceGoal $race, array $attributes): RaceGoal
    {
        if ($attributes['distance_m'] !== $race->distance_m) {
            throw ValidationException::withMessages([
                'distance_m' => 'A different distance is a different race. Choose "Add a new race" to start one.',
            ]);
        }

        $dateChanged = ! $race->race_date->isSameDay(Carbon::parse($attributes['race_date']));
        $targetChanged = $attributes['goal_time_sec'] !== $race->goal_time_sec;

        $race->fill([
            'race_date' => $attributes['race_date'],
            'goal_time_sec' => $attributes['goal_time_sec'],
            'name' => $attributes['name'] ?? null,
        ]);
        if (! $race->isDirty()) {
            return $race;
        }
        $race->save();

        if ($dateChanged || $targetChanged) {
            $this->record($race, $dateChanged ? RaceChangeKind::Postponed : RaceChangeKind::Revised);
        }

        return $race;
    }

    /**
     * @param  RaceAttributes  $attributes
     */
    private function replace(RaceGoal $previous, array $attributes): RaceGoal
    {
        if ($this->isSameEvent($previous, $attributes)) {
            return $previous;
        }

        $previous->completed_at = now();
        if ($previous->race_date->isFuture()) {
            $previous->outcome = RaceOutcome::Cancelled;
            $previous->outcome_recorded_at = now();
        }
        $previous->save();
        $this->record($previous, RaceChangeKind::Replaced);

        return $this->create($previous->user, $attributes);
    }

    /**
     * @param  RaceAttributes  $attributes
     */
    private function isSameEvent(RaceGoal $race, array $attributes): bool
    {
        return $race->race_date->isSameDay(Carbon::parse($attributes['race_date']))
            && $race->distance_m === $attributes['distance_m']
            && $race->goal_time_sec === $attributes['goal_time_sec']
            && $race->name === ($attributes['name'] ?? null);
    }

    public function record(RaceGoal $race, RaceChangeKind $kind): RaceGoalChange
    {
        return RaceGoalChange::query()->create([
            'race_goal_id' => $race->id,
            'user_id' => $race->user_id,
            'kind' => $kind,
            'race_date' => $race->race_date->toDateString(),
            'goal_time_sec' => $race->goal_time_sec,
            'outcome' => $race->outcome,
            'activity_id' => $race->outcome_activity_id,
            'finish_time_sec' => $race->finish_time_sec,
        ]);
    }

    private function forget(User $user): void
    {
        SharedPropCacheKey::ActiveRace->forget($user->id);
        $this->activeRace->forget($user->id);
    }
}
