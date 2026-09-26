<?php

declare(strict_types=1);

namespace App\Services\Run\Plan;

use Throwable;
use App\Enums\IntentVerdict;
use App\Jobs\Run\RecalibrateTrainingHistoryJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\ActivityPipeline;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final readonly class PlanRecalibrationService
{
    public function __construct(
        private ActivityPipeline $activityPipeline,
        private WeeklyAggregator $weeklyAggregator,
        private ComplianceScorer $complianceScorer,
        private Periodizer $periodizer,
        private IntensityPrescriptionResolver $prescriptionResolver,
        private VdotEstimator $vdotEstimator,
        private TrainingPaceCalculator $paceCalculator,
    ) {
    }

    /**
     * @return array{activities: int, snapshots: int, sessions: int, stale_narrations: int}
     */
    public function recalibrate(User $user, bool $dryRun = false, int $lockTtlSeconds = 3600): array
    {
        if ($user->is_demo) {
            throw new InvalidArgumentException('Demo users must be refreshed with demo:seed.');
        }

        $dirty = false;
        $result = Cache::lock(RecalibrateTrainingHistoryJob::overlapLockKey($user->id), $lockTtlSeconds)
            ->block(30, function () use ($user, $dryRun, &$dirty): array {
                $startedAt = Carbon::now();
                if (! $dryRun) {
                    $user->forceFill([
                        'plan_recalibration_started_at' => $startedAt,
                        'plan_recalibration_completed_at' => null,
                    ])->saveQuietly();
                }

                DB::beginTransaction();

                try {
                    $result = PlanRecalibrationDispatch::withoutDispatching(
                        fn (): array => $this->perform($user->fresh() ?? $user, $startedAt),
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
            });

        $lateDirty = ! $dryRun && Cache::get(RecalibrateTrainingHistoryJob::dirtyMarkerKey($user->id));
        if ($dirty || $lateDirty) {
            RecalibrateTrainingHistoryJob::dispatch($user->id)->delay(5)->afterCommit();
        }

        return $result;
    }

    /**
     * @return array{activities: int, snapshots: int, sessions: int, stale_narrations: int}
     */
    private function perform(User $user, Carbon $startedAt): array
    {
        $activities = Activity::query()
            ->where('user_id', $user->id)
            ->with(['detail', 'stream'])
            ->get()
            ->sortBy(fn (Activity $activity): string => $activity->detail?->start_date_local?->toIso8601String() ?? '')
            ->values();

        $recomputed = 0;
        foreach ($activities as $activity) {
            if ($activity->detail === null || $activity->stream === null || $activity->stream->data === []) {
                continue;
            }

            $this->activityPipeline->recomputeSummary($activity, rebuildAggregates: false, reconcileMaxHeartRate: false);
            $recomputed++;
        }

        $snapshots = $this->weeklyAggregator->rebuildFor($user);
        $sessions = $this->rewriteAndRegradeHistory($user);
        $this->periodizer->regenerate($user);

        $staleNarrations = Analysis::query()
            ->where('subject_type', AnalysisType::PLAN_DAY_VOICE_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::PlanDayVoice)
            ->where('status', AnalysisStatus::Done)
            ->whereDate('discriminator', '<', Carbon::today())
            ->update(['stale_at' => $startedAt]);

        return [
            'activities' => $recomputed,
            'snapshots' => $snapshots,
            'sessions' => $sessions,
            'stale_narrations' => $staleNarrations,
        ];
    }

    private function rewriteAndRegradeHistory(User $user): int
    {
        /** @var Collection<int, PlannedSession> $rows */
        $rows = PlannedSession::query()
            ->where('user_id', $user->id)
            ->whereDate('date', '<', Carbon::today())
            ->orderBy('date')
            ->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $seasons = Season::query()
            ->where('user_id', $user->id)
            ->with('raceGoal')
            ->orderBy('starts_at')
            ->get();

        /** @var array<string, array{date: Carbon, verdict: IntentVerdict, hard_minutes: int}> $recent */
        $recent = [];
        /** @var array<string, array{distance_m: int, goal_time_sec: int}|null> $raceByDate */
        $raceByDate = [];
        $count = 0;
        foreach ($rows as $row) {
            $season = $seasons->first(fn (Season $candidate): bool => $row->date->betweenIncluded($candidate->starts_at, $candidate->ends_at));
            $race = $season?->raceGoal;
            $raceByDate[$row->date->toDateString()] = $race === null
                ? null
                : ['distance_m' => (int) $race->distance_m, 'goal_time_sec' => (int) $race->goal_time_sec];
            $paces = $this->paceCalculator->fromVdotResult($this->vdotEstimator->estimate($user, $row->date));
            $family = IntensityPrescriptionResolver::familyKey(
                $row->session_type,
                $race === null ? null : (float) $race->distance_m,
                $race?->goal_time_sec,
            );
            $previous = $recent[$family] ?? null;
            if ($previous !== null && $previous['date']->diffInDays($row->date) > 42) {
                $previous = null;
            }

            $prescription = $this->prescriptionResolver->resolve(
                $row->session_type,
                $row->phase,
                $race === null ? null : (float) $race->distance_m,
                $race?->goal_time_sec,
                $paces,
                $previous['verdict'] ?? null,
                $previous['hard_minutes'] ?? null,
            );
            $row->update($prescription->toArray());

            $verdict = $this->complianceScorer->verdictsFor(
                $user,
                Collection::wrap([$row]),
                Carbon::today(),
                $raceByDate,
            )[$row->date->toDateString()] ?? null;
            if ($verdict === null) {
                continue;
            }

            ComplianceScorer::applyVerdict($row, $verdict);
            if ($row->intent_verdict !== null && $row->prescribed_hard_minutes !== null && $row->prescribed_hard_minutes > 0) {
                $recent[$family] = [
                    'date' => $row->date->copy(),
                    'verdict' => $row->intent_verdict,
                    'hard_minutes' => $row->prescribed_hard_minutes,
                ];
            }
            $count++;
        }

        return $count;
    }
}
