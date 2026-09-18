<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\PlanNarrationRequester;
use App\Services\AI\RecapHydrationReadiness;
use App\Services\AI\RecapPeriod;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

/**
 * Catches an athlete up on the narration deferred while they were away from the
 * app: the last week of runs, the latest closed week's and month's recaps and
 * this week's day reads go to the LLM, and anything older still pending is
 * filled rule-based. Queued from {@see \App\Http\Middleware\StampLastSeen} on
 * the first visit after the active window lapsed.
 *
 * Every request is invalidate:false and every fill skips a Done row, so a second
 * run bills nothing. Stamped {@see AnalysisOrigin::Return}, which
 * {@see AnalysisService::markDone()} never notifies for, and every one of this
 * job's own rule-based fills is written with `reason: AnalysisOrigin::Return`
 * ({@see Analysis::$rule_based_reason}), so the UI can cue "temari hasn't read
 * this one yet" on exactly the blocks this job filled and no other rule-based
 * reason.
 *
 * @see docs/decisions/narration-spends-only-on-active-athletes.md
 */
class NarrateOnReturnJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $userId)
    {
    }

    public function handle(
        AnalysisService $service,
        RecapHydrationReadiness $readiness,
        PlanNarrationRequester $planNarration,
    ): void {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Return);

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $windowStart = RecentlyActiveUsers::windowStart();

        $this->catchUpRuns($service, $user, $windowStart);
        $this->catchUpCardFlavors($service, $user, $windowStart);
        $this->catchUpWeeklyRecaps($service, $readiness, $user);
        $this->catchUpMonthlyRecaps($service, $readiness, $user);
        $this->readThisWeek($planNarration, $user);
    }

    private function catchUpRuns(AnalysisService $service, User $user, Carbon $windowStart): void
    {
        Activity::query()
            ->where('user_id', $user->id)
            ->whereHas('detail', fn (Builder $query) => $query->where('start_date_local', '<', $windowStart))
            ->whereHas('analyses', fn (Builder $query) => $query
                ->where('analysis_type', AnalysisType::PostRunSpeech)
                ->where('status', AnalysisStatus::Pending))
            ->get()
            ->each(fn (Activity $activity) => $service->requestActivityGroupRuleBased($activity, AnalysisOrigin::Return));

        $earliest = AnalyzeActivityJob::earliestPendingActivityForUser($user->id);
        if ($earliest !== null) {
            $service->requestActivityGroup($earliest, invalidate: false);
        }
    }

    private function catchUpCardFlavors(AnalysisService $service, User $user, Carbon $windowStart): void
    {
        $pending = Analysis::query()
            ->where('ai_analyses.subject_type', RunCard::class)
            ->where('ai_analyses.analysis_type', AnalysisType::CardFlavor)
            ->where('ai_analyses.status', AnalysisStatus::Pending)
            ->join('run_cards', 'run_cards.id', '=', 'ai_analyses.subject_id')
            ->join('activities', 'activities.id', '=', 'run_cards.activity_id')
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activities.user_id', $user->id)
            ->get(['ai_analyses.subject_id', 'activity_details.start_date_local']);

        foreach ($pending as $row) {
            $cardId = (int) $row->subject_id;

            if (Carbon::parse($row->getAttribute('start_date_local'))->lt($windowStart)) {
                $service->requestRuleBased(RunCard::class, $cardId, AnalysisType::CardFlavor, refillDone: false, reason: AnalysisOrigin::Return);

                continue;
            }

            $service->request(RunCard::class, $cardId, AnalysisType::CardFlavor, invalidate: false);
        }
    }

    private function catchUpWeeklyRecaps(AnalysisService $service, RecapHydrationReadiness $readiness, User $user): void
    {
        $lastClosed = RecapPeriod::lastClosedWeekEnding();
        $pending = fn (): Builder => WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('runs', '>', 0)
            ->whereHas('analyses', fn (Builder $query) => $query
                ->where('analysis_type', AnalysisType::WeeklyRecap)
                ->where('status', AnalysisStatus::Pending));

        $pending()->where('week_ending', '<', $lastClosed)->get()
            ->each(fn (WeeklySnapshot $snapshot) => $service->requestRuleBased(
                WeeklySnapshot::class,
                (int) $snapshot->id,
                AnalysisType::WeeklyRecap,
                refillDone: false,
                reason: AnalysisOrigin::Return,
            ));

        $readiness->ready($pending()->where('week_ending', $lastClosed)->get())
            ->each(fn (WeeklySnapshot $snapshot) => $service->request(
                WeeklySnapshot::class,
                (int) $snapshot->id,
                AnalysisType::WeeklyRecap,
                invalidate: false,
            ));
    }

    private function catchUpMonthlyRecaps(AnalysisService $service, RecapHydrationReadiness $readiness, User $user): void
    {
        $lastClosed = RecapPeriod::lastClosedMonth();
        $months = Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('subject_id', $user->id)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('status', AnalysisStatus::Pending)
            ->where('discriminator', '<=', $lastClosed)
            ->pluck('discriminator');

        $months->filter(fn (string $month): bool => $month < $lastClosed)
            ->each(fn (string $month) => $service->requestRuleBased(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, $month, refillDone: false, reason: AnalysisOrigin::Return));

        $readiness->readyMonths($user->id, $months->filter(fn (string $month): bool => $month === $lastClosed)->values())
            ->each(fn (string $month) => $service->request(AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE, $user->id, AnalysisType::MonthlyRecap, $month, invalidate: false));
    }

    private function readThisWeek(PlanNarrationRequester $planNarration, User $user): void
    {
        $today = Carbon::today();

        for ($day = $today->copy()->startOfWeek(Carbon::MONDAY); $day->lte($today); $day->addDay()) {
            $planNarration->requestDayVoiceIfChanged($user, $day->copy());
        }
    }
}
