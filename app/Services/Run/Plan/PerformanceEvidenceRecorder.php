<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use App\Enums\PlannedSessionStatus;
use App\Models\PerformanceEvidence;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The one validated path a confirmed race or test result takes into fitness evidence.
 *
 * @phpstan-type Fitness array{vdot: float, quality_vdot: float, vdot_source: array<string, mixed>, training_paces: array{easy: int, marathon: int, threshold: int, interval: int}|null}
 * @phpstan-type Recorded array{evidence: PerformanceEvidence, fitness: Fitness|null, plan_updated: bool}
 */
final readonly class PerformanceEvidenceRecorder
{
    private const float MIN_PLAUSIBLE_VDOT = 15.0;

    private const float MAX_PLAUSIBLE_VDOT = 90.0;

    public const int MIN_DISTANCE_M = 1_000;

    public const int MAX_DISTANCE_M = 42_195;

    public function __construct(
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $trainingPaceCalculator,
        private Periodizer $periodizer,
    ) {
    }

    public static function qualifies(float $distanceM): bool
    {
        return $distanceM >= self::MIN_DISTANCE_M && $distanceM <= self::MAX_DISTANCE_M;
    }

    public function assertPlausible(int $distanceM, int $elapsedTimeSec): void
    {
        $vdot = $this->vdotEstimator->vdotFromTimeAndDistance($elapsedTimeSec, $distanceM);
        if ($vdot === null || $vdot < self::MIN_PLAUSIBLE_VDOT || $vdot > self::MAX_PLAUSIBLE_VDOT) {
            throw ValidationException::withMessages(['elapsed_time_sec' => 'This time is not plausible for the distance.']);
        }
    }

    /**
     * @param  array{kind: string, distance_m: int, elapsed_time_sec: int, performed_on: string, activity_id?: int|null, race_goal_id?: int|null}  $attributes
     * @return Recorded
     */
    public function record(User $user, array $attributes): array
    {
        $this->assertPlausible($attributes['distance_m'], $attributes['elapsed_time_sec']);

        $before = $this->currentPaces($user);
        $evidenceAttributes = [...$attributes, 'user_id' => $user->id, 'confirmed_at' => now()];
        $activityId = $attributes['activity_id'] ?? null;
        $evidence = $activityId === null
            ? PerformanceEvidence::query()->create($evidenceAttributes)
            : PerformanceEvidence::query()->firstOrCreate(
                ['user_id' => $user->id, 'activity_id' => $activityId],
                $evidenceAttributes,
            );

        return $this->settle($user, $evidence, $before, $evidence->wasRecentlyCreated);
    }

    public function retractForRace(User $user, RaceGoal $race): void
    {
        $evidence = PerformanceEvidence::query()->where('user_id', $user->id)->where('race_goal_id', $race->id)->get();
        if ($evidence->isEmpty()) {
            return;
        }

        $this->retracting($user, fn () => PerformanceEvidence::query()->whereKey($evidence->modelKeys())->delete());
    }

    /** Runs $retract, then regenerates the plan if what it removed moved the training paces. */
    public function retracting(User $user, Closure $retract): void
    {
        $before = $this->currentPaces($user);
        $retract();
        $this->vdotEstimator->forget($user);
        $this->regenerateIfPacesChanged($user, $before, $this->currentPaces($user));
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $before
     * @return Recorded
     */
    private function settle(User $user, PerformanceEvidence $evidence, ?array $before, bool $created): array
    {
        $this->vdotEstimator->forget($user);
        $after = $this->vdotEstimator->estimate($user);
        $paces = $this->trainingPaceCalculator->fromVdotResult($after);
        $planUpdated = $created && $this->regenerateIfPacesChanged($user, $before, $paces);

        return [
            'evidence' => $evidence,
            'fitness' => $after === null ? null : [
                'vdot' => $after['vdot'],
                'quality_vdot' => $after['quality_vdot'],
                'vdot_source' => VdotEstimator::sourceSummary($after),
                'training_paces' => $paces,
            ],
            'plan_updated' => $planUpdated,
        ];
    }

    /**
     * @return array{easy: int, marathon: int, threshold: int, interval: int}|null
     */
    private function currentPaces(User $user): ?array
    {
        return $this->trainingPaceCalculator->fromVdotResult($this->vdotEstimator->estimate($user));
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $before
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $after
     */
    private function regenerateIfPacesChanged(User $user, ?array $before, ?array $after): bool
    {
        if (! self::pacesChanged($before, $after)) {
            return false;
        }

        $hasPlannedSessions = PlannedSession::query()->where('user_id', $user->id)
            ->where('status', PlannedSessionStatus::Planned)
            ->where('date', '>=', Carbon::today()->toDateString())
            ->exists();
        if (! $hasPlannedSessions) {
            return false;
        }

        try {
            $this->periodizer->regenerate($user, Carbon::today(), Periodizer::REQUEST_LOCK_WAIT_SECONDS);
        } catch (LockTimeoutException) {
            return false;
        }

        return true;
    }

    /**
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $before
     * @param  array{easy: int, marathon: int, threshold: int, interval: int}|null  $after
     */
    private static function pacesChanged(?array $before, ?array $after): bool
    {
        if ($before === null || $after === null) {
            return $before !== $after;
        }

        return array_any(
            ['easy', 'marathon', 'threshold', 'interval'],
            static fn ($pace): bool => abs($before[$pace] - $after[$pace]) >= 5,
        );
    }
}
