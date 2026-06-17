<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Jobs\AI\AnalyzeActivityJob;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:resume-chains')]
#[Description('Daily safety net: re-kick the earliest Pending link of every connected chain (weekly + monthly + per-activity) per user')]
class ResumeChainsCommand extends Command
{
    /**
     * Re-dispatches the earliest still-Pending link of each connected chain, so a
     * link stranded by a transient failure or a daily cost-ceiling pause resumes
     * the next day (once dailyCost() resets at midnight). invalidate:false makes
     * a capped dispatch a clean no-op, never the filler branch, so the chain
     * pauses rather than injecting rule-based prose; the job's afterDone hook
     * then walks forward to the rest.
     */
    public function handle(AnalysisService $service): int
    {
        $resumed = $this->resumeWeekly($service)
            + $this->resumeMonthly($service)
            + $this->resumePerActivity($service);

        $this->info("Resumed {$resumed} chains.");

        return self::SUCCESS;
    }

    /**
     * Per-activity chains: the user's earliest activity (by start_date_local)
     * whose narration group is still Pending. Dispatching it (invalidate:false)
     * re-kicks the group; AnalyzeActivityJob then walks forward. Includes demo,
     * which keeps its per-activity rows rule-based seeded (so a demo user rarely
     * has Pending activity groups), and any real user whose backfill paused.
     */
    private function resumePerActivity(AnalysisService $service): int
    {
        $userIds = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->whereNotNull('activity_details.start_date_local')
            ->whereHas('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::PostRunSpeech)
                ->where('status', AnalysisStatus::Pending))
            ->distinct()
            ->pluck('activities.user_id');

        $resumed = 0;
        foreach ($userIds as $userId) {
            $earliest = AnalyzeActivityJob::earliestPendingActivityForUser((int) $userId);
            if ($earliest === null) {
                continue;
            }
            $service->requestActivityGroup($earliest, invalidate: false);
            $resumed++;
        }

        return $resumed;
    }

    /**
     * Weekly chains: the earliest Pending WeeklyRecap per user (runs > 0).
     * Includes demo (weekly is demo-inclusive).
     */
    private function resumeWeekly(AnalysisService $service): int
    {
        $earliestPerUser = WeeklySnapshot::query()
            ->where('runs', '>', 0)
            ->whereHas('analyses', fn ($query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Pending))
            ->orderBy('week_ending')
            ->get(['id', 'user_id'])
            ->unique('user_id');

        foreach ($earliestPerUser as $snapshot) {
            $service->request(
                subjectOrType: WeeklySnapshot::class,
                subjectId: (int) $snapshot->id,
                type: AnalysisType::WeeklyRecap,
                invalidate: false,
            );
        }

        return $earliestPerUser->count();
    }

    /**
     * Monthly chains: the earliest Pending MonthlyRecap month per user. Demo
     * stays weekly-only, so it never stages a monthly row and is naturally
     * absent here.
     */
    private function resumeMonthly(AnalysisService $service): int
    {
        $earliestPerUser = Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('status', AnalysisStatus::Pending)
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
}
