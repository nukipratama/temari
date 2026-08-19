<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Re-dispatches the earliest still-stalled block of each recovery family so a
 * block stranded by a cost-ceiling pause resumes once the ceiling resets at
 * midnight, and a block stranded by a transient failure gets a bounded retry.
 * Every dispatch is invalidate:false, so it never fills a template and a
 * still-capped run is a clean no-op; the Failed-sweeping families are bounded
 * by {@see Analysis::MAX_SELF_HEAL_ATTEMPTS} so a terminally-broken block
 * drops out to the /ai-usage dead-letter instead of re-billing forever.
 */
class SelfHealer
{
    /**
     * Per-run drain cap for the non-cascading families (card flavor, PR context).
     * Re-kicking one of these narrates exactly one subject, so at 1/run a large
     * backfill would take one hour per card/PR; a bounded batch drains it in a few
     * runs. Billing stays capped regardless of this number: each dispatched job
     * re-checks the cost ceiling before calling the LLM (see
     * AnalyzeBaseJob::haltForPausedGeneration), so actual spend is bounded by the
     * ceiling, not by the batch size.
     */
    private const int NONCASCADING_DRAIN_BATCH = 10;

    /**
     * Spacing between successive real dispatches within one sweep run, so an
     * hourly sweep spreads its batch instead of firing every resumed link at
     * once. Sweep-internal fairness only — much smaller than the multi-minute
     * backfill stagger, since the proactive Azure rate limiter is the actual
     * backstop against a burst.
     */
    private const int SWEEP_SPACING_SECONDS = 5;

    public function __construct(
        private readonly AnalysisService $service,
        private readonly ChainResolver $chains,
        private readonly BackfillAgeGate $ages,
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
            + $this->resumePrContext()
            + $this->resumeSingleRowType(AnalysisType::BriefingMascotVoice)
            + $this->resumeSingleRowType(AnalysisType::BriefingFeaturedKartuVoice)
            + $this->resumeSingleRowType(AnalysisType::AkuProfileVoice);
    }

    /**
     * Per-activity chains: the user's earliest activity (by start_date_local)
     * whose narration group is *stalled* on its representative PostRunSpeech row
     * (Pending, or Failed still under the retry budget). Dispatching it
     * (invalidate:false) re-kicks the group; AnalyzeActivityJob then walks
     * forward. Unlike the Pending-only chain advance, a Failed-under-budget group
     * is auto-retried here too, so the biggest silent-rot class self-heals
     * instead of waiting on the run page's manual "Try again"; the attempts
     * budget still caps re-billing and dead-letters a terminally-broken group.
     * Demo is excluded (its per-activity rows are seeded Done, and this never
     * auto-bills a demo LLM call) to match the other five families.
     */
    private function resumePerActivity(): int
    {
        $resumed = 0;
        $index = 0;
        $service = $this->service;

        Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->whereNotNull('activity_details.start_date_local')
            ->whereIn('activities.user_id', User::query()->notDemo()->select('id'))
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
                    if ($earliest === null) {
                        continue;
                    }

                    $startedAt = $earliest->detail?->start_date_local;
                    if ($startedAt !== null && $startedAt->lt($oldestReal)) {
                        $service->requestActivityGroupRuleBased($earliest);
                        $resumed++;

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
     * {@see Analysis::STALE_IN_FLIGHT_HOURS} (its queue job was lost to a Redis
     * incident or ill-timed deploy) is reverted to Pending via the existing
     * revertToPending path, with no attempt burn. That puts it back into the
     * normal self-heal/dead-letter lifecycle: the family sweeps in this same run
     * re-dispatch the earliest, and the rest get picked up next hour. Without this
     * such a row shows an eternal "Lagi dipikirin Temari" skeleton, invisible to
     * every other recovery path.
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

    private function resumeWeekly(): int
    {
        $links = $this->chains->stalledWeeklyLinkPerUser();
        $oldestReal = $this->ages->cutoffDate();
        $index = 0;

        foreach ($links as $link) {
            // discriminator carries week_ending here (see ChainResolver::stalledWeeklyLinkPerUser).
            if ($link->discriminator !== null && $link->discriminator < $oldestReal) {
                $this->service->requestRuleBased(
                    subjectOrType: WeeklySnapshot::class,
                    subjectId: $link->subjectId,
                    type: AnalysisType::WeeklyRecap,
                );

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
        }

        return $links->count();
    }

    private function resumeMonthly(): int
    {
        $links = $this->chains->stalledMonthlyLinkPerUser();
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
     * {@see self::NONCASCADING_DRAIN_BATCH}. Unlike the daily/weekly-kickoff types,
     * CardFlavor is dispatched only at ingest and has no other scheduled recovery,
     * so a capped-Pending or transiently-Failed card would sit stuck without this
     * sweep. It does not cascade (one kick narrates one card), so a backfill of N
     * cards drains in ceil(N / batch) runs rather than N. Stalled + budget-bounded;
     * demo excluded.
     */
    private function resumeCardFlavor(): int
    {
        $toResume = Analysis::query()
            ->stalled()
            ->where('ai_analyses.subject_type', RunCard::class)
            ->where('ai_analyses.analysis_type', AnalysisType::CardFlavor)
            ->join('run_cards', 'run_cards.id', '=', 'ai_analyses.subject_id')
            ->join('activities', 'activities.id', '=', 'run_cards.activity_id')
            ->whereIn('activities.user_id', User::query()->notDemo()->select('id'))
            ->orderBy('ai_analyses.subject_id')
            ->get(['ai_analyses.subject_id', 'activities.user_id'])
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
     * PR-context narration: the earliest stalled PrContext rows per user, up to
     * {@see self::NONCASCADING_DRAIN_BATCH}. Like CardFlavor, dispatched only at
     * ingest with no other scheduled recovery and non-cascading, so it drains in
     * batches. Stalled + budget-bounded; ordered oldest-PR-first; demo excluded.
     */
    private function resumePrContext(): int
    {
        $toResume = Analysis::query()
            ->stalled()
            ->where('ai_analyses.subject_type', PersonalRecord::class)
            ->where('ai_analyses.analysis_type', AnalysisType::PrContext)
            ->join('personal_records', 'personal_records.id', '=', 'ai_analyses.subject_id')
            ->whereIn('personal_records.user_id', User::query()->notDemo()->select('id'))
            ->orderBy('personal_records.set_at')
            ->get(['ai_analyses.subject_id', 'personal_records.user_id'])
            ->groupBy('user_id')
            ->flatMap(fn ($rows) => $rows->take(self::NONCASCADING_DRAIN_BATCH));

        $toResume->values()->each(fn ($row, int $index) => $this->service->request(
            subjectOrType: PersonalRecord::class,
            subjectId: (int) $row->subject_id,
            type: AnalysisType::PrContext,
            delaySeconds: $index * self::SWEEP_SPACING_SECONDS,
            invalidate: false,
        ));

        return $toResume->count();
    }

    /**
     * Single-row-per-user narration types with no chain/group of their own:
     * BriefingMascotVoice, BriefingFeaturedKartuVoice, AkuProfileVoice. Each is
     * dispatched only at its own kickoff (daily briefing / weekly profile) with
     * no other scheduled recovery, so a capped-Pending or transiently-Failed row
     * would sit stuck without this sweep. subject_id is the user id directly for all of these
     * types, so no join is needed to scope by user. Stalled + budget-bounded;
     * demo excluded; re-dispatched against the stalled row's own discriminator
     * (not recomputed) so a resumed BriefingFeaturedKartuVoice still targets the
     * card it originally narrated.
     *
     * Every other type's discriminator is a zero-padded date/week string, so a
     * plain string ORDER BY is chronological. BriefingFeaturedKartuVoice's
     * discriminator is a bare card id instead, which a string sort gets wrong
     * across a digit-count boundary ('10' sorts before '9'), so it orders by
     * the numeric value instead to still land on the truly-earliest stalled row.
     */
    private function resumeSingleRowType(AnalysisType $type): int
    {
        $earliestPerUser = Analysis::query()
            ->stalled()
            ->where('subject_type', $type->subjectType())
            ->where('analysis_type', $type)
            ->whereIn('subject_id', User::query()->notDemo()->select('id'))
            ->when(
                $type === AnalysisType::BriefingFeaturedKartuVoice,
                fn ($query) => $query->orderByRaw('CAST(discriminator AS UNSIGNED)'),
                fn ($query) => $query->orderBy('discriminator'),
            )
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
