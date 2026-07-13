<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Jobs\AI\AnalyzeBriefingJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use Illuminate\Database\Eloquent\Builder;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaintainerAlerter;
use App\Services\AI\RecapPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ai:self-heal')]
#[Description('Hourly safety net: re-kick the earliest stalled AI block per user (chains + card/PR/briefing/profile narration), under a retry budget')]
class SelfHealCommand extends Command
{
    /**
     * Re-dispatches the earliest still-stalled block of each recovery family so a
     * block stranded by a cost-ceiling pause resumes once the ceiling resets at
     * midnight, and a block stranded by a transient failure gets a bounded retry.
     * Every dispatch is invalidate:false, so it never fills a template and a
     * still-capped run is a clean no-op; the Failed-sweeping families are bounded
     * by {@see Analysis::MAX_SELF_HEAL_ATTEMPTS} so a terminally-broken block
     * drops out to the /ai-usage dead-letter instead of re-billing forever.
     */
    public function handle(AnalysisService $service, MaintainerAlerter $alerter): int
    {
        // Detect a pause on/off transition before the early-exit, so both entering
        // a pause (healthy -> paused) and resuming (paused -> healthy) push an alert
        // once, with the reason. Runs hourly regardless of the current pause state.
        $alerter->syncPauseState($service->pauseReason());

        // Nothing this run can dispatch would bill while generation is paused
        // (cost ceiling / AI off / Azure unset) - every request() would no-op -
        // so skip the per-user queries until it clears.
        if ($service->generationPaused()) {
            $this->info('Skipped: AI generation is paused (cost ceiling / AI off / Azure unset).');

            return self::SUCCESS;
        }

        // Rescue in-flight zombies first, so a row a lost queue job left stuck in
        // Queued/Processing is back to Pending before the family sweeps run and
        // can re-dispatch the earliest of them in this same pass.
        $resumed = $this->revertStaleInFlight($service)
            + $this->resumeWeekly($service)
            + $this->resumeMonthly($service)
            + $this->resumePerActivity($service)
            + $this->resumeCardFlavor($service)
            + $this->resumePrContext($service)
            + $this->resumeBriefingGroup($service)
            + $this->resumeSingleRowType($service, AnalysisType::BriefingMascotVoice)
            + $this->resumeSingleRowType($service, AnalysisType::BriefingFeaturedKartuVoice)
            + $this->resumeSingleRowType($service, AnalysisType::DailyGreeting)
            + $this->resumeSingleRowType($service, AnalysisType::TrendCaption)
            + $this->resumeSingleRowType($service, AnalysisType::PersonaSummary)
            + $this->resumeSingleRowType($service, AnalysisType::AkuProfileVoice);

        $this->info("Resumed {$resumed} blocks.");

        return self::SUCCESS;
    }

    /**
     * Per-activity chains: the user's earliest activity (by start_date_local)
     * whose narration group is *stalled* on its representative PostRunSpeech row
     * (Pending, or Failed still under the retry budget). Dispatching it
     * (invalidate:false) re-kicks the group; AnalyzeActivityJob then walks
     * forward. Unlike the Pending-only chain advance, a Failed-under-budget group
     * is auto-retried here too, so the biggest silent-rot class self-heals
     * instead of waiting on the run page's manual "Coba lagi"; the attempts
     * budget still caps re-billing and dead-letters a terminally-broken group.
     * Demo is excluded (its per-activity rows are seeded Done, and this never
     * auto-bills a demo LLM call) to match the other five families.
     */
    private function resumePerActivity(AnalysisService $service): int
    {
        $resumed = 0;

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
            ->chunkById(100, function ($users) use ($service, &$resumed): void {
                foreach ($users as $row) {
                    $earliest = AnalyzeActivityJob::earliestStalledActivityForUser((int) $row->user_id);
                    if ($earliest === null) {
                        continue;
                    }
                    $service->requestActivityGroup($earliest, invalidate: false);
                    $resumed++;
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
    private function revertStaleInFlight(AnalysisService $service): int
    {
        $threshold = Carbon::now()->subHours(Analysis::STALE_IN_FLIGHT_HOURS);

        $stale = Analysis::query()->staleInFlight($threshold)->get();

        foreach ($stale as $row) {
            $service->revertToPending($row);
        }

        return $stale->count();
    }

    /**
     * Weekly chains: the earliest stalled WeeklyRecap per user (runs > 0) among
     * the fully-closed weeks. "Stalled" = Pending or Failed under the retry
     * budget ({@see Analysis::scopeStalled}), so this recovers a link a transient
     * failure or cost-ceiling pause left behind without re-billing a block that
     * has burned its budget. Capped at the latest closed week so the sweep never
     * narrates the still-running current week on incomplete data (the weekly
     * kickoff owns first-narration). Demo is excluded so it never auto-bills.
     */
    private function resumeWeekly(AnalysisService $service): int
    {
        $lastWeekEnding = RecapPeriod::lastClosedWeekEnding();

        $earliestPerUser = Analysis::query()
            ->stalled()
            ->where('ai_analyses.subject_type', WeeklySnapshot::class)
            ->where('ai_analyses.analysis_type', AnalysisType::WeeklyRecap)
            ->join('weekly_snapshots', 'weekly_snapshots.id', '=', 'ai_analyses.subject_id')
            ->where('weekly_snapshots.runs', '>', 0)
            ->where('weekly_snapshots.week_ending', '<=', $lastWeekEnding)
            ->whereIn('weekly_snapshots.user_id', User::query()->notDemo()->select('id'))
            ->orderBy('weekly_snapshots.week_ending')
            ->get(['ai_analyses.subject_id', 'weekly_snapshots.user_id'])
            ->unique('user_id');

        foreach ($earliestPerUser as $row) {
            $service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $row->subject_id,
                type: AnalysisType::WeeklyRecap,
                invalidate: false,
            );
        }

        return $earliestPerUser->count();
    }

    /**
     * Monthly chains: the earliest stalled MonthlyRecap month per user among the
     * fully-closed months. "Stalled" = Pending or Failed under the retry budget.
     * Capped at the latest closed month so the sweep never narrates the
     * still-running current month (the monthly kickoff owns first-narration).
     * Demo never stages a monthly row, so it is naturally absent here.
     */
    private function resumeMonthly(AnalysisService $service): int
    {
        $lastClosedMonth = RecapPeriod::lastClosedMonth();

        $earliestPerUser = Analysis::query()
            ->stalled()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('discriminator', '<=', $lastClosedMonth)
            ->orderBy('discriminator')
            ->get(['subject_id', 'discriminator'])
            ->unique('subject_id');

        foreach ($earliestPerUser as $row) {
            $service->request(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: (int) $row->subject_id,
                type: AnalysisType::MonthlyRecap,
                discriminator: $row->discriminator,
                invalidate: false,
            );
        }

        return $earliestPerUser->count();
    }

    /**
     * Card-flavor narration: the earliest stalled CardFlavor per user. Unlike the
     * daily/weekly-kickoff types, CardFlavor is dispatched only at ingest and has
     * no other scheduled recovery, so a capped-Pending or transiently-Failed card
     * would sit stuck without this sweep. Stalled + budget-bounded; demo excluded.
     */
    private function resumeCardFlavor(AnalysisService $service): int
    {
        $earliestPerUser = Analysis::query()
            ->stalled()
            ->where('ai_analyses.subject_type', RunCard::class)
            ->where('ai_analyses.analysis_type', AnalysisType::CardFlavor)
            ->join('run_cards', 'run_cards.id', '=', 'ai_analyses.subject_id')
            ->join('activities', 'activities.id', '=', 'run_cards.activity_id')
            ->whereIn('activities.user_id', User::query()->notDemo()->select('id'))
            ->orderBy('ai_analyses.subject_id')
            ->get(['ai_analyses.subject_id', 'activities.user_id'])
            ->unique('user_id');

        foreach ($earliestPerUser as $row) {
            $service->request(
                subjectOrType: RunCard::class,
                subjectId: (int) $row->subject_id,
                type: AnalysisType::CardFlavor,
                invalidate: false,
            );
        }

        return $earliestPerUser->count();
    }

    /**
     * PR-context narration: the earliest stalled PrContext per user. Like
     * CardFlavor, dispatched only at ingest with no other scheduled recovery.
     * Stalled + budget-bounded; ordered oldest-PR-first; demo excluded.
     */
    private function resumePrContext(AnalysisService $service): int
    {
        $earliestPerUser = Analysis::query()
            ->stalled()
            ->where('ai_analyses.subject_type', PersonalRecord::class)
            ->where('ai_analyses.analysis_type', AnalysisType::PrContext)
            ->join('personal_records', 'personal_records.id', '=', 'ai_analyses.subject_id')
            ->whereIn('personal_records.user_id', User::query()->notDemo()->select('id'))
            ->orderBy('personal_records.set_at')
            ->get(['ai_analyses.subject_id', 'personal_records.user_id'])
            ->unique('user_id');

        foreach ($earliestPerUser as $row) {
            $service->request(
                subjectOrType: PersonalRecord::class,
                subjectId: (int) $row->subject_id,
                type: AnalysisType::PrContext,
                invalidate: false,
            );
        }

        return $earliestPerUser->count();
    }

    /**
     * Briefing group (headline + suggestion): the earliest stalled briefing day
     * per user, matched on *any* stalled grouped type, not only the headline
     * representative. A BriefingSuggestion left Failed under a healthy (Done)
     * headline is otherwise never swept, so it would rot with no recovery and no
     * visibility. Dispatching via {@see AnalysisService::request()} with the
     * headline type resolves the group job and re-runs both rows together;
     * AnalyzeBriefingJob is idempotent for the already-Done sibling, so a Done
     * headline is not re-billed. Stalled + budget-bounded; demo excluded;
     * discriminator is a zero-padded date, so a plain string ORDER BY is
     * chronological.
     */
    private function resumeBriefingGroup(AnalysisService $service): int
    {
        $groupedValues = array_map(
            fn (AnalysisType $type): string => $type->value,
            AnalyzeBriefingJob::groupedTypes(),
        );

        $earliestPerUser = Analysis::query()
            ->stalled()
            ->where('subject_type', AnalysisType::BRIEFING_SUBJECT_TYPE)
            ->whereIn('analysis_type', $groupedValues)
            ->whereIn('subject_id', User::query()->notDemo()->select('id'))
            ->orderBy('discriminator')
            ->get(['subject_id', 'discriminator'])
            ->unique('subject_id');

        foreach ($earliestPerUser as $row) {
            $service->request(
                subjectOrType: AnalysisType::BRIEFING_SUBJECT_TYPE,
                subjectId: (int) $row->subject_id,
                // The headline is the group's representative; request() resolves
                // AnalyzeBriefingJob from it and re-dispatches the whole group.
                type: AnalysisType::BriefingHeadline,
                discriminator: $row->discriminator,
                invalidate: false,
            );
        }

        return $earliestPerUser->count();
    }

    /**
     * Single-row-per-user narration types with no chain/group of their own:
     * BriefingMascotVoice, BriefingFeaturedKartuVoice, DailyGreeting,
     * TrendCaption, PersonaSummary, AkuProfileVoice. Each is dispatched only at
     * its own kickoff (daily briefing / weekly profile) with no other scheduled
     * recovery, so a capped-Pending or transiently-Failed row would sit stuck
     * without this sweep. subject_id is the user id directly for all of these
     * types, so no join is needed to scope by user. Stalled + budget-bounded;
     * demo excluded; re-dispatched against the stalled row's own discriminator
     * (not recomputed) so a resumed BriefingFeaturedKartuVoice still targets the
     * card it originally narrated. The grouped briefing types (headline +
     * suggestion) are swept by {@see self::resumeBriefingGroup()} instead.
     *
     * Every other type's discriminator is a zero-padded date/week string, so a
     * plain string ORDER BY is chronological. BriefingFeaturedKartuVoice's
     * discriminator is a bare card id instead, which a string sort gets wrong
     * across a digit-count boundary ('10' sorts before '9'), so it orders by
     * the numeric value instead to still land on the truly-earliest stalled row.
     */
    private function resumeSingleRowType(AnalysisService $service, AnalysisType $type): int
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

        foreach ($earliestPerUser as $row) {
            $service->request(
                subjectOrType: $type->subjectType(),
                subjectId: (int) $row->subject_id,
                type: $type,
                discriminator: $row->discriminator,
                invalidate: false,
            );
        }

        return $earliestPerUser->count();
    }
}
