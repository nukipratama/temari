<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use Throwable;
use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\Activity;
use App\Models\User;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class PlanRecalibrationService
{
    private const int ACTIVITY_BATCH_SIZE = 25;

    public function __construct(
        private ActivityPipeline $activityPipeline,
        private WeeklyAggregator $weeklyAggregator,
        private Periodizer $periodizer,
    ) {
    }

    /**
     * Ordinary recalibration after a zone change or ingest: run metrics, weekly
     * snapshots and the future plan follow the current zones. Past
     * prescriptions and the grades given against shown advice are kept.
     *
     * @return array{activities: int, snapshots: int}
     */
    public function recalibrate(User $user, bool $dryRun = false, int $lockTtlSeconds = 3600): array
    {
        return $this->exclusively($user, $dryRun, $lockTtlSeconds, function (User $user): array {
            $activities = $this->recomputeSummaries($user);
            $snapshots = $this->weeklyAggregator->rebuildFor($user);
            $this->periodizer->regenerateWithinLock($user);

            return ['activities' => $activities, 'snapshots' => $snapshots];
        });
    }

    /**
     * Runs $work for one non-demo user under the recalibration and plan
     * regeneration locks, inside one transaction that a dry run rolls back,
     * with the recalibration progress markers around it.
     *
     * @template T
     *
     * @param  callable(User): T  $work
     * @return T
     */
    public function exclusively(User $user, bool $dryRun, int $lockTtlSeconds, callable $work): mixed
    {
        if ($user->is_demo) {
            throw new InvalidArgumentException('Demo users must be refreshed with demo:seed.');
        }

        $dirty = false;
        $result = Cache::lock(RecalibrateTrainingHistoryJob::overlapLockKey($user->id), $lockTtlSeconds)
            ->block(30, function () use ($user, $dryRun, $lockTtlSeconds, $work, &$dirty): mixed {
                return $this->periodizer->withRegenerationLock($user, function () use ($user, $dryRun, $work, &$dirty): mixed {
                    if (! $dryRun) {
                        $user->forceFill([
                            'plan_recalibration_started_at' => Carbon::now(),
                            'plan_recalibration_completed_at' => null,
                        ])->saveQuietly();
                    }

                    DB::beginTransaction();

                    try {
                        $result = PlanRecalibrationDispatch::withoutDispatching(
                            fn (): mixed => $work($user->fresh() ?? $user),
                        );

                        if ($dryRun) {
                            DB::rollBack();
                        } else {
                            DB::commit();
                            $user->forceFill(['plan_recalibration_completed_at' => Carbon::now()])->saveQuietly();
                            if (Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id))) {
                                $dirty = true;
                                Cache::forget(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id));
                            }
                        }

                        return $result;
                    } catch (Throwable $exception) {
                        if (DB::transactionLevel() > 0) {
                            DB::rollBack();
                        }

                        throw $exception;
                    }
                }, lockTtlSeconds: $lockTtlSeconds);
            });

        $lateDirty = ! $dryRun && Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id));
        if ($dirty || $lateDirty) {
            RecalibrateTrainingHistoryJob::dispatch($user->id)->delay(5)->afterCommit();
        }

        return $result;
    }

    /** Recomputes every stored run's summary and TRIMP under the current zones, oldest first. */
    public function recomputeSummaries(User $user): int
    {
        $activities = Activity::query()
            ->select('activities.*')
            ->leftJoin('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $user->id)
            ->with(['detail', 'stream'])
            ->orderBy('activity_details.start_date_local')
            ->orderBy('activities.id')
            ->lazy(self::ACTIVITY_BATCH_SIZE);

        $recomputed = 0;
        foreach ($activities as $activity) {
            try {
                if ($activity->detail === null || $activity->stream === null || $activity->stream->data === []) {
                    continue;
                }

                $this->activityPipeline->recomputeSummary($activity, rebuildAggregates: false, reconcileMaxHeartRate: false);
                $recomputed++;
            } finally {
                $activity->unsetRelation('detail');
                $activity->unsetRelation('stream');
            }
        }

        return $recomputed;
    }
}
