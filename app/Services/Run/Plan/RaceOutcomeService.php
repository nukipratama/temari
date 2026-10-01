<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PerformanceEvidenceKind;
use App\Enums\RaceChangeKind;
use App\Enums\RaceOutcome;
use App\Models\PerformanceEvidence;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Gamification\SeasonRecordBuilder;
use App\Support\SharedPropCacheKey;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Records what became of a race on its date. Nothing is graded or counted until an outcome other
 * than pending is recorded, and any recorded outcome can be corrected later.
 *
 * @phpstan-import-type Candidate from RaceOutcomeMatcher
 */
final readonly class RaceOutcomeService
{
    public function __construct(
        private RaceOutcomeMatcher $matcher,
        private RaceGoalService $races,
        private PerformanceEvidenceRecorder $evidence,
        private SeasonRecordBuilder $seasonRecords,
    ) {
    }

    public function record(
        User $user,
        RaceGoal $race,
        RaceOutcome $outcome,
        ?int $activityId = null,
        ?int $finishTimeSec = null,
    ): RaceGoal {
        if ($race->user_id !== $user->id) {
            throw new AuthorizationException();
        }
        if ($race->race_date->isFuture()) {
            throw ValidationException::withMessages(['outcome' => 'This race has not happened yet.']);
        }

        $result = $this->resolveResult($race, $outcome, $activityId, $finishTimeSec);

        $changed = DB::transaction(function () use ($user, $race, $outcome, $result): bool {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $race->refresh();

            if ($race->outcome === $outcome
                && $race->outcome_activity_id === $result['activity_id']
                && $race->finish_time_sec === $result['finish_time_sec']) {
                return false;
            }

            $race->forceFill([
                'outcome' => $outcome,
                'outcome_activity_id' => $result['activity_id'],
                'finish_time_sec' => $result['finish_time_sec'],
                'outcome_recorded_at' => now(),
                'completed_at' => $race->completed_at ?? now(),
            ])->save();
            $this->races->record($race, RaceChangeKind::Outcome);

            return true;
        });

        if ($changed) {
            $this->seasonRecords->settleForRace($race);
            $this->evidence->retractForRace($user, $race);
        }
        if ($outcome === RaceOutcome::Confirmed) {
            $this->ensureEvidence($user, $race, $result);
        }
        SharedPropCacheKey::ActiveRace->forget($user->id);

        return $race->load('changes');
    }

    /**
     * @return array{activity_id: int|null, finish_time_sec: int|null, distance_m: int|null}
     */
    private function resolveResult(RaceGoal $race, RaceOutcome $outcome, ?int $activityId, ?int $finishTimeSec): array
    {
        if ($outcome !== RaceOutcome::Confirmed) {
            return ['activity_id' => null, 'finish_time_sec' => null, 'distance_m' => null];
        }

        if ($activityId !== null && $finishTimeSec === null) {
            $match = $this->matcher->candidates($race)->firstWhere('activity_id', $activityId);
            if ($match === null) {
                throw ValidationException::withMessages(['activity_id' => 'That run is not a match for this race.']);
            }
            $result = ['activity_id' => $activityId, 'finish_time_sec' => $match['elapsed_time_sec'], 'distance_m' => (int) round($match['distance_m'])];
        } elseif ($activityId === null && $finishTimeSec !== null) {
            $result = ['activity_id' => null, 'finish_time_sec' => $finishTimeSec, 'distance_m' => $race->distance_m];
        } else {
            throw ValidationException::withMessages(['outcome' => 'Confirm a race with either its run or a finish time.']);
        }

        if (PerformanceEvidenceRecorder::qualifies((float) $result['distance_m'])) {
            $this->evidence->assertPlausible($result['distance_m'], $result['finish_time_sec']);
        }

        return $result;
    }

    /**
     * @param  array{activity_id: int|null, finish_time_sec: int|null, distance_m: int|null}  $result
     */
    private function ensureEvidence(User $user, RaceGoal $race, array $result): void
    {
        if ($result['distance_m'] === null || $result['finish_time_sec'] === null || ! PerformanceEvidenceRecorder::qualifies((float) $result['distance_m'])) {
            return;
        }
        if (PerformanceEvidence::query()->where('user_id', $user->id)->where('race_goal_id', $race->id)->exists()) {
            return;
        }

        $this->evidence->record($user, [
            'kind' => PerformanceEvidenceKind::Race->value,
            'distance_m' => $result['distance_m'],
            'elapsed_time_sec' => $result['finish_time_sec'],
            'performed_on' => $race->race_date->toDateString(),
            'activity_id' => $result['activity_id'],
            'race_goal_id' => $race->id,
        ]);
    }
}
