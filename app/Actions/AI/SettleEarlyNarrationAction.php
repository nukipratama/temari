<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Actions\Run\Story\RecomputeCardClaimsAction;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisSubjectMap;
use App\Services\AI\AnalysisType;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Metrics\PersonalRecords;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The replay a fresh connect's early pass owes once its history finishes
 * hydrating: real PRs and cards in date order (#1022), then exactly one
 * regeneration of every row the early pass narrated ahead of that history —
 * see docs/decisions/history-narrates-on-demand.md.
 *
 * The exactly-once guarantee is the claim on {@see User::$history_replay_due_at}
 * itself: one conditional UPDATE (`whereNotNull`, checked for one affected row)
 * both asks whether a replay is owed and takes it, the same pattern
 * {@see AnalysisService::claimForDispatch()} uses. A second call — another
 * ingest also finding nothing left to hydrate, an unrelated `ai:self-heal`
 * sweep, a racing concurrent ingest — claims nothing and does no work. The PR
 * rebuild and card replay run whenever the claim succeeds, whether or not any
 * row was ever marked `narrated_early_at`: PR detection can be deferred
 * ({@see \App\Services\Run\Ingest\ActivityPipeline}) even when its narration
 * finishes late enough to never need marking at all.
 *
 * The Trends read ({@see \App\Jobs\AI\KickoffRecapsJob}) and a still-hydrating
 * month's recap ({@see KickoffMonthlyRecaps}) never get an Analysis row at all
 * while deferred — there is nothing to mark or claim for them — so they are
 * asked for directly here on every successful claim; both are naturally
 * idempotent (an existing row is left alone, a Done month is skipped).
 */
class SettleEarlyNarrationAction
{
    public function __construct(
        private readonly PersonalRecords $personalRecords,
        private readonly RecomputeCardClaimsAction $recomputeCardClaims,
        private readonly AnalysisService $analysisService,
        private readonly PlanNarrationRequester $planNarration,
        private readonly KickoffMonthlyRecaps $kickoffMonthlyRecaps,
    ) {
    }

    public function __invoke(User $user): void
    {
        if ($user->history_replay_due_at === null) {
            return;
        }

        /** @var Collection<int, Analysis>|null $claimed */
        $claimed = DB::transaction(function () use ($user): ?Collection {
            $won = User::query()
                ->whereKey($user->id)
                ->whereNotNull('history_replay_due_at')
                ->update(['history_replay_due_at' => null]) === 1;

            if (! $won) {
                return null;
            }

            $rows = AnalysisSubjectMap::whereOwnedBy(Analysis::query(), $user->id)
                ->whereNotNull('narrated_early_at')
                ->lockForUpdate()
                ->get();

            if ($rows->isNotEmpty()) {
                Analysis::query()->whereIn('id', $rows->pluck('id'))->update([
                    'narrated_early_at' => null,
                    'error' => null,
                ]);

                // Every other early type is unconditionally re-requested below,
                // so flipping it to Pending here is safe. PlanDayVoice is the
                // exception — requestDayVoiceIfChanged() decides for itself
                // whether the day's material actually changed, and it has no
                // SelfHealer recovery family, so pre-flipping it here could
                // strand it Pending with nothing behind it if it decides not to.
                $toPending = $rows->reject(fn (Analysis $row): bool => $row->analysis_type === AnalysisType::PlanDayVoice);
                if ($toPending->isNotEmpty()) {
                    Analysis::query()->whereIn('id', $toPending->pluck('id'))->update([
                        'status' => AnalysisStatus::Pending,
                    ]);
                }
            }

            return $rows;
        });

        if ($claimed === null) {
            return;
        }

        $this->personalRecords->rebuildForUser($user);
        ($this->recomputeCardClaims)($user);

        if ($claimed->isNotEmpty()) {
            $this->regenerate($user, $claimed);
        }

        $this->requestDeferredTrendReads($user);
        ($this->kickoffMonthlyRecaps)($user->id);
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
