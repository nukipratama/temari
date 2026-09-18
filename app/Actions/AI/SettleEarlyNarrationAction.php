<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Actions\Run\Story\RecomputeCardClaimsAction;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\AnalysisType;
use App\Services\AI\HydrationBacklog;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The replay a fresh connect's early pass owes once its history finishes
 * hydrating: real PRs and cards in date order, then exactly one regeneration
 * of every row the early pass narrated ahead of that history — see
 * docs/decisions/history-narrates-on-demand.md.
 *
 * Called from every point an ingest or a give-up can leave the backlog empty
 * ({@see \App\Services\Run\Ingest\ActivityPipeline}). It is a no-op unless the
 * backlog is actually empty and the athlete is still inside the hydration
 * grace window, so a long-connected athlete's every ingest returns
 * immediately. The claim is per row: an early row is only ever picked up by
 * the call that clears its own `narrated_early_at`, so a second call (a
 * racing ingest, an unrelated sweep) finds nothing left to claim. The PR
 * rebuild and card recompute run on every successful call regardless, since
 * both are idempotent and PR detection can be deferred even when its
 * narration finishes late enough to never need marking at all.
 */
class SettleEarlyNarrationAction
{
    public function __construct(
        private readonly PersonalRecords $personalRecords,
        private readonly RecomputeCardClaimsAction $recomputeCardClaims,
        private readonly AnalysisService $analysisService,
        private readonly PlanNarrationRequester $planNarration,
        private readonly HydrationBacklog $backlog,
    ) {
    }

    public function __invoke(User $user): void
    {
        if (! $this->backlog->withinHydrationGrace($user->id)
            || $this->backlog->awaitingHydration([$user->id])->exists()) {
            return;
        }

        /** @var Collection<int, Analysis> $claimed */
        $claimed = DB::transaction(fn (): Collection => $this->claimEarlyRows($user));

        $this->personalRecords->rebuildForUser($user);
        ($this->recomputeCardClaims)($user);

        if ($claimed->isNotEmpty()) {
            $this->regenerate($user, $claimed);
        }

        $this->requestDeferredTrendReads($user);
    }

    /**
     * Clears `narrated_early_at` on every early row this athlete owns, pairing
     * a lone early RunInsight or PostRunSpeech with its sibling so the
     * per-activity group's representative row is never left Done while the
     * other resets alone — {@see AnalyzeActivityJob}'s chain advance and
     * SelfHealer both key on PostRunSpeech's status.
     *
     * @return Collection<int, Analysis>
     */
    private function claimEarlyRows(User $user): Collection
    {
        $rows = AnalysisSubjectMap::whereOwnedBy(Analysis::query(), $user->id)
            ->whereNotNull('narrated_early_at')
            ->lockForUpdate()
            ->get();

        if ($rows->isEmpty()) {
            return $rows;
        }

        $activityIds = $rows
            ->filter(fn (Analysis $row): bool => in_array($row->analysis_type, [AnalysisType::PostRunSpeech, AnalysisType::RunInsight], true))
            ->pluck('subject_id')
            ->unique();

        if ($activityIds->isNotEmpty()) {
            $siblings = Analysis::query()
                ->where('subject_type', AnalyzeActivityJob::subjectType())
                ->whereIn('analysis_type', [AnalysisType::PostRunSpeech, AnalysisType::RunInsight])
                ->whereIn('subject_id', $activityIds)
                ->lockForUpdate()
                ->get();

            $rows = $rows->merge($siblings)->unique('id')->values();
        }

        Analysis::query()->whereIn('id', $rows->pluck('id'))->update([
            'narrated_early_at' => null,
            'error' => null,
        ]);

        // PlanDayVoice is the exception: requestDayVoiceIfChanged() decides for
        // itself whether the day's material changed, and has no SelfHealer
        // recovery family, so pre-flipping it to Pending here could strand it.
        $toPending = $rows->reject(fn (Analysis $row): bool => $row->analysis_type === AnalysisType::PlanDayVoice);
        if ($toPending->isNotEmpty()) {
            Analysis::query()->whereIn('id', $toPending->pluck('id'))->update([
                'status' => AnalysisStatus::Pending,
            ]);
        }

        return $rows;
    }

    /**
     * Mirrors {@see \App\Jobs\AI\KickoffRecapsJob::kickoffTrendReads()}'s own
     * guard: skipped for an athlete whose backfill found no runs, and a no-op
     * for a range already requested (`request()`'s own idempotent upsert).
     */
    private function requestDeferredTrendReads(User $user): void
    {
        if (! Activity::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        foreach (AnalysisType::TREND_READ_RANGES as $range) {
            $this->analysisService->request(
                subjectOrType: AnalysisType::TrendRead->subjectType(),
                subjectId: $user->id,
                type: AnalysisType::TrendRead,
                discriminator: $range,
            );
        }
    }

    /**
     * @param  Collection<int, Analysis>  $rows
     */
    private function regenerate(User $user, Collection $rows): void
    {
        $activityIds = [];

        foreach ($rows as $row) {
            match ($row->analysis_type) {
                AnalysisType::PostRunSpeech, AnalysisType::RunInsight => $activityIds[] = $row->subject_id,
                AnalysisType::CardFlavor => $this->analysisService->request(
                    subjectOrType: RunCard::class,
                    subjectId: $row->subject_id,
                    type: AnalysisType::CardFlavor,
                    invalidate: false,
                ),
                AnalysisType::BriefingMascotVoice => $this->analysisService->requestBriefing(
                    $user,
                    (string) $row->discriminator,
                    invalidate: false,
                ),
                AnalysisType::ProfileVoice => $this->analysisService->requestProfileVoice(
                    $user,
                    (string) $row->discriminator,
                    invalidate: false,
                ),
                AnalysisType::PlanDayVoice => $row->discriminator !== null
                    ? $this->planNarration->requestDayVoiceIfChanged($user, Carbon::parse($row->discriminator))
                    : null,
                default => null,
            };
        }

        // The claimed activities are contiguous Pending links (drain complete
        // means nothing older is left): dispatching the earliest lets
        // AnalyzeActivityJob's own chain-advance walk the rest forward.
        if ($activityIds === []) {
            return;
        }

        $earliest = Activity::query()
            ->whereIn('activities.id', array_unique($activityIds))
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->orderBy('activity_details.start_date_local')
            ->select('activities.*')
            ->first();

        if ($earliest !== null) {
            $this->analysisService->requestActivityGroup($earliest, invalidate: false);
        }
    }
}
