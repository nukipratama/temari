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

/**
 * The replay a fresh connect's early pass owes once its history finishes
 * hydrating: real PRs and cards in date order, then exactly one regeneration
 * of every row the early pass narrated ahead of that history — see
 * docs/decisions/history-narrates-on-demand.md.
 *
 * Called from every point an ingest or a give-up can leave the backlog empty
 * ({@see \App\Services\Run\Ingest\ActivityPipeline}); a no-op unless the
 * backlog is actually empty, so a long-connected athlete's every ingest
 * returns immediately. The claim is per row, so a second call (a racing
 * ingest, an unrelated sweep) finds nothing left to claim.
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
        if ($this->backlog->awaitingHydration([$user->id])->exists()) {
            return;
        }

        $claimableRows = AnalysisSubjectMap::whereOwnedBy(Analysis::query(), $user->id)
            ->whereNotNull('narrated_early_at');

        // Past the grace window this only proceeds if there is still
        // something left to claim, so a drain that outran the grace window
        // gets its one replay instead of being silently skipped forever.
        if (! $this->backlog->withinHydrationGrace($user->id) && ! $claimableRows->exists()) {
            return;
        }

        // Rebuilt and recomputed before the claim, and unconditionally: both
        // are idempotent, and PR detection can be deferred even when its
        // narration finishes late enough to never need marking at all.
        $this->personalRecords->rebuildForUser($user);
        ($this->recomputeCardClaims)($user);

        $claimed = $this->claimEarlyRows($user);

        if ($claimed->isNotEmpty()) {
            $this->regenerate($user, $claimed);
        }

        $this->analysisService->requestTrendReads($user);
    }

    /**
     * Clears `narrated_early_at` on every early row this athlete owns, pairing
     * a lone early RunInsight or PostRunSpeech with its sibling so the
     * per-activity group's representative row is never left Done while the
     * other resets alone — {@see AnalyzeActivityJob}'s chain advance and
     * SelfHealer both key on PostRunSpeech's status. Each row is claimed by
     * its own single-row conditional UPDATE rather than a bulk lockForUpdate,
     * so a concurrent settle for the same user cannot deadlock against
     * PersonalRecords's own row locking.
     *
     * @return Collection<int, Analysis>
     */
    private function claimEarlyRows(User $user): Collection
    {
        $candidates = AnalysisSubjectMap::whereOwnedBy(Analysis::query(), $user->id)
            ->whereNotNull('narrated_early_at')
            ->get();

        if ($candidates->isEmpty()) {
            return $candidates;
        }

        $activityIds = $candidates
            ->filter(fn (Analysis $row): bool => in_array($row->analysis_type, [AnalysisType::PostRunSpeech, AnalysisType::RunInsight], true))
            ->pluck('subject_id')
            ->unique();

        if ($activityIds->isNotEmpty()) {
            $siblings = Analysis::query()
                ->where('subject_type', AnalyzeActivityJob::subjectType())
                ->whereIn('analysis_type', [AnalysisType::PostRunSpeech, AnalysisType::RunInsight])
                ->whereIn('subject_id', $activityIds)
                ->get();

            $candidates = $candidates->merge($siblings)->unique('id')->values();
        }

        return $candidates->filter(fn (Analysis $row): bool => $this->claimRow($row));
    }

    /**
     * One conditional UPDATE per row: clears `narrated_early_at` and, unless
     * this is a PlanDayVoice row, sends it back to Pending. PlanDayVoice is
     * the exception — requestDayVoiceIfChanged() decides for itself whether
     * the day's material changed, and has no SelfHealer recovery family, so
     * pre-flipping it to Pending here could strand it. A sibling swept in
     * alongside its own early-marked pair (see claimEarlyRows) may already
     * have no `narrated_early_at` of its own, so only a row that has one is
     * required to still have it — that's the only row a second caller could
     * otherwise race. Returns whether this call actually claimed the row.
     */
    private function claimRow(Analysis $row): bool
    {
        $attributes = ['narrated_early_at' => null, 'error' => null];
        if ($row->analysis_type !== AnalysisType::PlanDayVoice) {
            $attributes['status'] = AnalysisStatus::Pending;
        }

        $query = Analysis::query()->whereKey($row->getKey());
        if ($row->narrated_early_at !== null) {
            $query->whereNotNull('narrated_early_at');
        }

        $claimed = $query->update($attributes) === 1;

        if ($claimed) {
            $row->forceFill($attributes)->syncOriginal();
        }

        return $claimed;
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

        // Dispatching the earliest claimed activity lets AnalyzeActivityJob's
        // own chain-advance walk the rest forward.
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
