<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\AI\StaggerBackfillAction;
use App\Events\ActivityIngested;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\BackfillAgeGate;
use App\Services\AI\MaterialFingerprint;
use App\Services\Run\Metrics\WeeklyAggregator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

/**
 * Owns the post-ingest AI analysis fan-out. Queued so it runs in its own job,
 * independent of the Strava ingest job's retry envelope. Each request is an
 * idempotent upsert, so a re-run never double-bills the LLM.
 */
class DispatchPostRunAnalysis implements ShouldQueue
{
    public function __construct(
        private readonly AnalysisService $analysisService,
        private readonly WeeklyAggregator $weeklyAggregator,
        private readonly StaggerBackfillAction $staggerBackfill,
        private readonly BackfillAgeGate $ageGate,
    ) {
    }

    public function handle(ActivityIngested $event): void
    {
        $activity = Activity::query()->with(['detail', 'user', 'runCard'])->find($event->activityId);
        if ($activity === null || $activity->detail === null) {
            return;
        }

        $user = $activity->user;
        $detail = $activity->detail;
        $tooOld = $this->ageGate->isTooOld($detail->start_date_local);

        $today = Carbon::today()->toDateString();
        $isBackfill = $this->isBackfill($detail);
        $delaySec = $isBackfill ? ($this->staggerBackfill)($activity->user_id) : 0;
        $isToday = $detail->start_date_local?->toDateString() === $today;

        $this->requestPrContext($activity, $tooOld, $delaySec);
        $this->requestCardFlavor($activity, $tooOld, $delaySec);

        $this->dispatchActivityGroup($activity, $isBackfill, $tooOld, $delaySec);

        // Daily cadence: when the ingested run is today's, refresh the whole
        // daily AI set so each block narrates with every run done so far today.
        // Backfill of a previous day leaves the Done rows untouched, so
        // re-ingesting old days never re-bills.
        $this->analysisService->requestBriefing($user, $today, invalidate: $isToday, delaySeconds: $delaySec);

        $this->analysisService->request(
            subjectOrType: AnalysisType::ProfileVoice->subjectType(),
            subjectId: $user->id,
            type: AnalysisType::ProfileVoice,
            discriminator: AnalysisType::currentIsoWeek(),
            delaySeconds: $delaySec,
            invalidate: false,
        );

        if ($detail->start_date_local === null) {
            return;
        }
        $snapshot = $this->weeklyAggregator->rebuildForwardFrom($user, $detail->start_date_local);
        if ($snapshot !== null) {
            // Weekly cadence: regenerating the recap of a still-unfinished week
            // on every run was the single biggest LLM re-bill. The row is staged
            // Pending here; ai:weekly-recap narrates it once the week closes.
            // "Reread" can still force a mid-week narration on demand.
            $this->analysisService->requestDeferred(
                WeeklySnapshot::class,
                $snapshot->id,
                AnalysisType::WeeklyRecap,
            );
        }

        // Monthly cadence: same deferred staging keyed by the run's month (Y-m).
        // The chain narrates it once the month closes (ai:monthly-recap kickoff +
        // the daily resume sweep). Demo stays weekly-only, so it never stages a
        // monthly row.
        if (! $user->is_demo) {
            $this->analysisService->requestDeferred(
                AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                $user->id,
                AnalysisType::MonthlyRecap,
                $detail->start_date_local->format('Y-m'),
            );
        }
    }

    /**
     * The records this run currently holds, i.e. the ones its ingest just beat.
     *
     * invalidate:false so a chronological backfill (each historical run
     * beats the same category record in turn) does not re-bill pr_context on
     * every beat: the idempotency guard skips a row that is already Done. The
     * narrator reads the live PR row at job time, so a still-pending row
     * narrates the LATEST value regardless of how many beats preceded it.
     */
    private function requestPrContext(Activity $activity, bool $tooOld, int $delaySec): void
    {
        $prIds = PersonalRecord::query()
            ->where('activity_id', $activity->id)
            ->orderBy('id')
            ->pluck('id');

        foreach ($prIds as $prId) {
            if ($tooOld) {
                $this->analysisService->requestRuleBased(
                    subjectOrType: PersonalRecord::class,
                    subjectId: (int) $prId,
                    type: AnalysisType::PrContext,
                    refillDone: false,
                );

                continue;
            }

            $this->analysisService->request(
                subjectOrType: PersonalRecord::class,
                subjectId: (int) $prId,
                type: AnalysisType::PrContext,
                delaySeconds: $delaySec,
                invalidate: false,
            );
        }
    }

    private function requestCardFlavor(Activity $activity, bool $tooOld, int $delaySec): void
    {
        $card = $activity->runCard;
        if ($card === null) {
            return;
        }

        if ($tooOld) {
            $this->analysisService->requestRuleBased(
                subjectOrType: RunCard::class,
                subjectId: $card->id,
                type: AnalysisType::CardFlavor,
                refillDone: false,
            );

            return;
        }

        $this->analysisService->request(
            subjectOrType: RunCard::class,
            subjectId: $card->id,
            delaySeconds: $delaySec,
            type: AnalysisType::CardFlavor,
            invalidate: true,
        );
    }

    /**
     * Activities started more than `ai.backfill_threshold_hours` ago are
     * treated as backfill. A null start timestamp is steady-state (dispatch
     * now), since the chronological chain has no place to slot an undated run.
     */
    private function isBackfill(ActivityDetail $detail): bool
    {
        $startedAt = $detail->start_date_local;
        if ($startedAt === null) {
            return false;
        }

        $thresholdHours = (int) config('ai.backfill_threshold_hours', 24);

        return Carbon::now()->diffInHours($startedAt, absolute: true) >= $thresholdHours;
    }

    /**
     * Backfilled (old) runs stage their narration group Pending and let the
     * chain narrate them one activity at a time, oldest first: each ingest
     * stages its own group, and the kickoff dispatches the user's earliest
     * Pending group, whose AnalyzeActivityJob then walks forward. Dispatching
     * the staged earliest (invalidate:false) keeps it ceiling-safe (a tripped
     * cost ceiling is a clean no-op, never the filler branch). Steady-state
     * (fresh) runs keep the existing single immediate dispatch + graceful
     * prev-lookup (the prior activity is already Done).
     *
     * A fresh (non-backfill) ingest still joins the chain instead of firing
     * immediately when an older link for this user is already unresolved —
     * otherwise a live run can narrate ahead of an in-progress backfill,
     * both racing it for Azure calls and breaking the connected story's
     * chronological continuity (today's run would reference the wrong
     * "previous" narrative). The fast path (dispatchActivityGroup's steady-
     * state branch) is reserved for the common case: the chain is already
     * caught up.
     */
    private function dispatchActivityGroup(Activity $activity, bool $isBackfill, bool $tooOld, int $delaySec): void
    {
        if ($tooOld) {
            $this->analysisService->requestActivityGroupRuleBased($activity);

            return;
        }

        $hasUnfinishedOlderLink = AnalyzeActivityJob::earliestPendingActivityForUser($activity->user_id) !== null;

        if (! $isBackfill && ! $hasUnfinishedOlderLink) {
            $this->maybeRefreshActivityGroup($activity);

            return;
        }

        $this->analysisService->requestActivityGroupDeferred($activity);

        $earliest = AnalyzeActivityJob::earliestPendingActivityForUser($activity->user_id);
        if ($earliest !== null) {
            $this->analysisService->requestActivityGroup($earliest, invalidate: false, delaySeconds: $delaySec);
        }
    }

    /**
     * Steady-state (fresh or re-synced) dispatch. A first ingest just dispatches
     * the group as normal (Pending rows narrate). On a re-sync of the user's
     * LATEST run, if the material run data actually changed since it was last
     * narrated — and its re-narrate cooldown has elapsed — invalidate the Done
     * group so it re-narrates the corrected data; otherwise it's a no-op, never
     * re-billing on Strava's byte-level jitter. Older runs are left to the manual
     * "Reread" so the connected story that quotes them doesn't desync.
     */
    private function maybeRefreshActivityGroup(Activity $activity): void
    {
        if (Activity::latestIdForUser($activity->user_id) !== $activity->id) {
            $this->analysisService->requestActivityGroup($activity);

            return;
        }

        $this->analysisService->requestActivityGroup(
            $activity,
            invalidate: $this->materialRefreshDue($activity),
        );
    }

    /**
     * Whether the latest run's material data changed since its narration was
     * generated and its cooldown has elapsed. A null stored fingerprint (a
     * pre-feature run, never stamped) counts as unchanged, so shipping this never
     * mass-invalidates history — only forward, on a genuine change.
     */
    private function materialRefreshDue(Activity $activity): bool
    {
        $speech = Analysis::query()
            ->forSubject(Activity::class, $activity->id, AnalysisType::PostRunSpeech)
            ->first();

        if ($speech === null
            || $speech->status !== AnalysisStatus::Done
            || $speech->content_fingerprint === null) {
            return false;
        }

        if ($speech->content_fingerprint === MaterialFingerprint::forActivity($activity)) {
            return false;
        }

        return ($speech->cooldownRemaining() ?? 0) <= 0;
    }

}
