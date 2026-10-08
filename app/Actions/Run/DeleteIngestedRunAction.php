<?php

declare(strict_types=1);

namespace App\Actions\Run;

use App\Actions\Run\Plan\ResolveTrailingWeeksAction;
use App\Enums\PerformanceEvidenceKind;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PerformanceEvidence;
use App\Models\RunCard;
use App\Models\Scopes\KnownAnalysisTypeScope;
use App\Models\WeeklySnapshot;
use App\Services\Run\Metrics\PersonalRecords;
use App\Services\Run\Metrics\WeeklyAggregator;
use App\Services\Run\Plan\ComplianceScorer;
use App\Services\Run\Plan\PerformanceEvidenceRecorder;
use App\Services\Run\Plan\PlanReconciliationService;
use App\Services\Run\Trend\TrendSnapshotRepairService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Deletes an ingested run and heals the artifacts that don't cascade: the FK
 * cascade drops detail / streams / card / post-run storyline, but the plan
 * day's verdict and the weekly snapshot are recomputed from separate
 * aggregates, PRs are only ever lowered (so a deleted run's record lingers),
 * and the polymorphic Analysis rows have no FK. A time trial confirmed from the
 * run is retracted with it, while a race result the athlete confirmed stays.
 * Shared by a Strava delete and a resync that re-types the run to a non-run sport.
 */
class DeleteIngestedRunAction
{
    public function __construct(
        private readonly WeeklyAggregator $weekly,
        private readonly PersonalRecords $personalRecords,
        private readonly ResolveTrailingWeeksAction $weeklySnapshots,
        private readonly ComplianceScorer $complianceScorer,
        private readonly PlanReconciliationService $planReconciliation,
        private readonly TrendSnapshotRepairService $trendSnapshots,
        private readonly PerformanceEvidenceRecorder $evidence,
    ) {
    }

    public function __invoke(Activity $activity): void
    {
        $activity->loadMissing(['user', 'detail', 'runCard']);
        $user = $activity->user;
        $weekAnchor = $activity->detail?->start_date_local;
        $localId = $activity->id;
        // The card cascades on delete, but its CardFlavor analysis (keyed by the
        // card id, no FK) does not — capture the id now to purge it below.
        $cardId = $activity->runCard?->id;
        $runTests = PerformanceEvidence::query()
            ->where('activity_id', $localId)
            ->where('kind', PerformanceEvidenceKind::Test)
            ->whereNull('race_goal_id');

        $delete = fn () => DB::transaction(function () use ($activity, $weekAnchor, $user, $localId, $cardId, $runTests): void {
            $runTests->delete();

            // Cascades detail / stream / card / post-run storyline via FK.
            $activity->delete();

            if ($weekAnchor !== null) {
                $this->complianceScorer->regradeAfterDelete($user, $weekAnchor, Carbon::today());
            }

            if ($weekAnchor !== null) {
                $rebuilt = $this->weekly->rebuildForwardFrom($user, $weekAnchor);

                // rebuildForwardFrom no-ops on an empty lookback window (e.g. the
                // user's only run was the deleted one), which would leave a stale
                // snapshot claiming runs that no longer exist. Drop the now-empty
                // forward snapshots in that case.
                if ($rebuilt === null) {
                    $anchorWeekEnding = Carbon::instance($weekAnchor)->endOfWeek(Carbon::SUNDAY)->startOfDay();
                    WeeklySnapshot::query()
                        ->where('user_id', $user->id)
                        ->where('week_ending', '>=', $anchorWeekEnding->toDateString())
                        ->delete();
                    $this->weeklySnapshots->forget($user->id);
                }
            }

            // PRs only ever lower, so a deleted run's record must be rebuilt from the
            // surviving runs rather than left pointing at a deleted activity.
            $this->personalRecords->rebuildForUser($user);

            // Polymorphic narration has no FK; purge the deleted run's rows (the
            // activity-keyed speech + insights, and the card-keyed flavor).
            // withoutGlobalScope so a retired-type row for this subject (see
            // KnownAnalysisTypeScope) is purged too instead of surviving the
            // very activity/card it belonged to.
            Analysis::query()
                ->withoutGlobalScope(KnownAnalysisTypeScope::class)
                ->where('subject_type', Activity::class)
                ->where('subject_id', $localId)
                ->delete();

            if ($cardId !== null) {
                Analysis::query()
                    ->withoutGlobalScope(KnownAnalysisTypeScope::class)
                    ->where('subject_type', RunCard::class)
                    ->where('subject_id', $cardId)
                    ->delete();
            }
        });

        if ($runTests->exists()) {
            $this->evidence->retracting($user, $delete);
        } else {
            $delete();
        }

        if ($weekAnchor !== null) {
            $this->planReconciliation->markDirty($user->id, $weekAnchor);
            $this->trendSnapshots->markDirty($user->id, $weekAnchor);
        }
    }
}
