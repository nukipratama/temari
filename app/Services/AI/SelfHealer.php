<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\WeeklySnapshot;
use App\Services\Run\Ingest\HydrationBacklog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Re-dispatches the earliest still-stalled block of each recovery family so a block stranded by a
 * cost-ceiling pause resumes once the ceiling resets at midnight, and a block stranded by a
 * transient failure gets a bounded retry.
 *
 * Every dispatch is invalidate:false, so a still-capped run is a clean no-op; the Failed-sweeping
 * families are bounded by {@see Analysis::MAX_SELF_HEAL_ATTEMPTS}, after which a terminally-broken
 * block drops out to the /devtools/narration dead-letter.
 *
 * Every family sweeps only {@see RecentlyActiveUsers}; an athlete away from the app is caught up by
 * {@see \App\Jobs\AI\NarrateOnReturnJob}. A run or card whose older history is still hydrating
 * waits for a later sweep ({@see HydrationBacklog::awaitsOlderHydration()}).
 */
class SelfHealer
{
    /**
     * Per-run drain cap for the non-cascading families (card flavor, PR context). Spend stays
     * bounded by the ceiling, not this number, because each dispatched job re-checks the cost
     * ceiling before calling the LLM (see AnalyzeBaseJob::haltForPausedGeneration).
     */
    private const int NONCASCADING_DRAIN_BATCH = 10;

    /**
     * Spacing between successive real dispatches within one sweep run, so an hourly sweep spreads
     * its batch instead of firing every resumed link at once.
     */
    private const int SWEEP_SPACING_SECONDS = 5;

    public function __construct(
        private readonly AnalysisService $service,
        private readonly ChainResolver $chains,
        private readonly BackfillAgeGate $ages,
        private readonly RecapHydrationReadiness $readiness,
        private readonly RecentlyActiveUsers $activeUsers,
        private readonly HydrationBacklog $backlog,
    ) {
    }

    /**
     * Rescue in-flight zombies first, so a row a lost queue job left stuck in
     * Queued/Processing is back to Pending before the family sweeps run and
     * can re-dispatch the earliest of them in this same pass.
     */
    public function run(): int
    {
        return $this->revertStaleInFlight()
            + $this->resumeWeekly()
            + $this->resumeMonthly()
            + $this->resumePerActivity()
            + $this->resumeCardFlavor()
            + $this->resumeSingleRowType(AnalysisType::BriefingMascotVoice)
            + $this->resumeSingleRowType(AnalysisType::ProfileVoice)
            + $this->resumeSingleRowType(AnalysisType::TrendRead)
            + $this->resumeSingleRowType(AnalysisType::PlanClampVoice, Carbon::today()->toDateString());
    }

    /**
     * One fresh attempt, once a pause lifts, for every active athlete's block that
     * failed from an hour (one sweep) before the pause began. Each is set one
     * attempt short of {@see Analysis::MAX_SELF_HEAL_ATTEMPTS}, so a further
     * failure dead-letters it again and re-alerts.
     */
    public function retryFailedDuringPause(Carbon $pauseStartedAt): int
    {
        $index = 0;

        foreach ($this->activeUsers->ids() as $userId) {
            $rows = AnalysisSubjectMap::whereOwnedBy(
                Analysis::query()
                    ->knownType()
                    ->where('status', AnalysisStatus::Failed)
                    ->where('updated_at', '>=', $pauseStartedAt->copy()->subHour()),
                $userId,
            )->get();

            foreach ($rows as $row) {
                $row->update(['attempts' => Analysis::MAX_SELF_HEAL_ATTEMPTS - 1]);

                $this->service->request(
                    subjectOrType: $row->subject_type,
                    subjectId: $row->subject_id,
                    type: $row->analysis_type,
                    discriminator: $row->discriminator,
                    delaySeconds: $index * self::SWEEP_SPACING_SECONDS,
                    invalidate: false,
                );
                $index++;
            }
        }

        return $index;
    }

    /**
     * Per-activity chains: the user's earliest activity (by start_date_local) whose narration group
     * is stalled on its representative PostRunSpeech row (Pending, or Failed still under the retry
     * budget). Dispatching it (invalidate:false) re-kicks the group and AnalyzeActivityJob walks
     * forward. The demo is excluded, like the other families.
     */
    private function resumePerActivity(): int
    {
        $resumed = 0;
        $index = 0;
        $service = $this->service;

        Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->whereNotNull('activity_details.start_date_local')
            ->whereIn('activities.user_id', $this->activeUsers->query()->select('id'))
            ->whereHas('analyses', function ($query): void {
                /** @var Builder<Analysis> $query */
                $query
                    ->where('analysis_type', AnalysisType::PostRunSpeech)
                    ->stalled();
            })
            ->distinct()
            ->select('activities.user_id')
            ->chunkById(100, function ($users) use ($service, &$resumed, &$index): void {
                $oldestReal = $this->ages->cutoff();

                foreach ($users as $row) {
                    $earliest = AnalyzeActivityJob::earliestStalledActivityForUser((int) $row->user_id);
                    if (!$earliest instanceof Activity) {
                        continue;
                    }

                    $startedAt = $earliest->detail?->start_date_local;
                    if ($startedAt !== null && $startedAt->lt($oldestReal)) {
                        $service->requestActivityGroupRuleBased($earliest);
                        $resumed++;

                        continue;
                    }

                    if ($this->backlog->awaitsOlderHydration((int) $row->user_id, $startedAt)) {
                        continue;
                    }

                    $service->requestActivityGroup($earliest, invalidate: false, delaySeconds: $index * self::SWEEP_SPACING_SECONDS);
                    $resumed++;
                    $index++;
                }
            }, 'activities.user_id', 'user_id');

        return $resumed;
    }

    /**
     * Stale in-flight sweep: a row stuck Queued/Processing past
     * {@see Analysis::STALE_IN_FLIGHT_HOURS} (its queue job was lost) is reverted to Pending via the
     * existing revertToPending path, with no attempt burn, which puts it back into the normal
     * self-heal/dead-letter lifecycle.
     */
    private function revertStaleInFlight(): int
    {
        $threshold = Carbon::now()->subHours(Analysis::STALE_IN_FLIGHT_HOURS);

        $stale = Analysis::query()->staleInFlight($threshold)->get();

        foreach ($stale as $row) {
            $this->service->revertToPending($row);
        }

        return $stale->count();
    }

    /**
     * The hourly pickup for a week {@see RecapHydrationReadiness} held back:
     * the recap row is already staged Pending by the ingest cascade, so it
     * surfaces here every hour and resumes on the first sweep after its
     * activities finish hydrating.
     */
    private function resumeWeekly(): int
    {
        $stalled = $this->chains->stalledWeeklyLinkPerUser();
        /** @var Collection<int, int|string> $weekOwners */
        $weekOwners = WeeklySnapshot::query()
            ->whereIn('id', $stalled->map(fn (ChainLink $link): int => $link->subjectId)->all())
            ->whereIn('user_id', $this->activeUsers->query()->select('id'))
            ->pluck('user_id', 'id');
        $activeWeekOwners = $weekOwners->map(fn (int|string $userId): int => (int) $userId)->all();
        $links = $stalled->filter(fn (ChainLink $link): bool => isset($activeWeekOwners[$link->subjectId]));
        $connectedAt = $this->backlog->connectedAtFor(array_values(array_unique($activeWeekOwners)));
        $preConnect = fn (ChainLink $link): bool => $link->discriminator !== null
            && RecapPeriod::weekClosedBeforeConnect($link->discriminator, $connectedAt[$activeWeekOwners[$link->subjectId]] ?? null);
        $oldestReal = $this->ages->cutoffDate();

        // discriminator carries week_ending here (see ChainResolver::stalledWeeklyLinkPerUser).
        $tooOld = fn (ChainLink $link): bool => $link->discriminator !== null && $link->discriminator < $oldestReal;

        $ready = $this->readyWeekIds($links);
        $resumed = 0;
        $index = 0;

        foreach ($links as $link) {
            if (! $ready->has($link->subjectId)) {
                continue;
            }

            if ($tooOld($link) || $preConnect($link)) {
                $this->service->requestRuleBased(
                    subjectOrType: WeeklySnapshot::class,
                    subjectId: $link->subjectId,
                    type: AnalysisType::WeeklyRecap,
                );
                $resumed++;

                continue;
            }

            $this->service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: $link->subjectId,
                type: AnalysisType::WeeklyRecap,
                delaySeconds: $index * self::SWEEP_SPACING_SECONDS,
                invalidate: false,
            );
            $index++;
            $resumed++;
        }

        return $resumed;
    }

    /**
     * @param  Collection<int, ChainLink>  $links
     * @return Collection<int, int>  the snapshot ids whose week may be narrated now, keyed by id
     */
    private function readyWeekIds(Collection $links): Collection
    {
        $snapshots = WeeklySnapshot::query()
            ->whereIn('id', $links->map(fn (ChainLink $link): int => $link->subjectId)->all())
            ->get();

        return $this->readiness->ready($snapshots)->map(fn (WeeklySnapshot $snapshot): int => $snapshot->id)->flip();
    }

    private function resumeMonthly(): int
    {
        $activeIds = array_flip($this->activeUsers->ids());
        $links = $this->chains->stalledMonthlyLinkPerUser()
            ->filter(fn (ChainLink $link): bool => isset($activeIds[$link->subjectId]))
            ->values();
        $oldestRealMonth = $this->ages->cutoffMonth();
        $index = 0;

        foreach ($links as $link) {
            if ($link->discriminator !== null && $link->discriminator < $oldestRealMonth) {
                $this->service->requestRuleBased(
                    subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                    subjectId: $link->subjectId,
                    type: AnalysisType::MonthlyRecap,
                    discriminator: $link->discriminator,
                );

                continue;
            }

            // A month whose own runs are still hydrating (KickoffMonthlyRecaps'
            // own deferral) is left for the next sweep rather than resumed.
            if ($link->discriminator !== null && $this->backlog->monthAwaitsHydration($link->subjectId, $link->discriminator)) {
                continue;
            }

            if ($link->discriminator !== null && RecapPeriod::monthClosedBeforeConnect($link->discriminator, $this->backlog->connectedAt($link->subjectId))) {
                $this->service->requestRuleBased(
                    subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                    subjectId: $link->subjectId,
                    type: AnalysisType::MonthlyRecap,
                    discriminator: $link->discriminator,
                );

                continue;
            }

            $this->service->request(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: $link->subjectId,
                type: AnalysisType::MonthlyRecap,
                discriminator: $link->discriminator,
                delaySeconds: $index * self::SWEEP_SPACING_SECONDS,
                invalidate: false,
            );
            $index++;
        }

        return $links->count();
    }

    /**
     * Card-flavor narration: the earliest stalled CardFlavor rows per user, up to
     * {@see self::NONCASCADING_DRAIN_BATCH}. CardFlavor is dispatched only at ingest and does not
     * cascade (one kick narrates one card), so this sweep is its only recovery. Stalled +
     * budget-bounded; demo excluded.
     */
    private function resumeCardFlavor(): int
    {
        // Not AnalyzedScope-guarded: a RunCard is only ever built (ActivityPipeline)
        // once its activity is analyzed, so this join can't reach a stub — the
        // predicate would be a no-op here, not a bug fix.
        $toResume = Analysis::query()
            ->stalled()
            ->where('ai_analyses.subject_type', RunCard::class)
            ->where('ai_analyses.analysis_type', AnalysisType::CardFlavor)
            ->join('run_cards', 'run_cards.id', '=', 'ai_analyses.subject_id')
            ->join('activities', 'activities.id', '=', 'run_cards.activity_id')
            ->leftJoin('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->whereIn('activities.user_id', $this->activeUsers->query()->select('id'))
            ->orderBy('ai_analyses.subject_id')
            ->get(['ai_analyses.subject_id', 'activities.user_id', 'activity_details.start_date_local'])
            ->reject(function (Analysis $row): bool {
                $userId = $row->getAttribute('user_id');
                assert(is_numeric($userId));

                return $this->backlog->awaitsOlderHydration(
                    (int) $userId,
                    Carbon::make($row->getAttribute('start_date_local')),
                );
            })
            ->groupBy('user_id')
            ->flatMap(fn ($rows) => $rows->take(self::NONCASCADING_DRAIN_BATCH));

        $toResume->values()->each(fn ($row, int $index) => $this->service->request(
            subjectOrType: RunCard::class,
            subjectId: (int) $row->subject_id,
            type: AnalysisType::CardFlavor,
            delaySeconds: $index * self::SWEEP_SPACING_SECONDS,
            invalidate: false,
        ));

        return $toResume->count();
    }

    /**
     * Single-row-per-user narration types with no chain/group of their own: BriefingMascotVoice,
     * ProfileVoice, TrendRead and PlanClampVoice. Each is dispatched only at its own kickoff, so
     * this sweep is its only recovery. Stalled + budget-bounded; demo excluded; re-dispatched
     * against the stalled row's own discriminator rather than a recomputed one.
     *
     * The discriminators are zero-padded date/week strings, so a plain string ORDER BY is
     * chronological.
     */
    private function resumeSingleRowType(AnalysisType $type, ?string $onlyDiscriminator = null): int
    {
        $earliestPerUser = Analysis::query()
            ->stalled()
            ->where('subject_type', $type->subjectType())
            ->where('analysis_type', $type)
            ->whereIn('subject_id', $this->activeUsers->query()->select('id'))
            ->when($onlyDiscriminator !== null, fn (Builder $query) => $query->where('discriminator', $onlyDiscriminator))
            ->when($type === AnalysisType::BriefingMascotVoice, fn (Builder $query) => $query->whereNotExists(
                PlannedSession::query()
                    ->whereColumn('planned_sessions.user_id', 'ai_analyses.subject_id')
                    ->whereColumn('planned_sessions.date', 'ai_analyses.discriminator')
                    ->where('skipped', true),
            ))
            ->orderBy('discriminator')
            ->get(['subject_id', 'discriminator'])
            ->unique('subject_id');

        $earliestPerUser->values()->each(fn ($row, int $index) => $this->service->request(
            subjectOrType: $type->subjectType(),
            subjectId: (int) $row->subject_id,
            type: $type,
            discriminator: $row->discriminator,
            delaySeconds: $index * self::SWEEP_SPACING_SECONDS,
            invalidate: false,
        ));

        return $earliestPerUser->count();
    }
}
