<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\BackfillAgeGate;
use App\Services\AI\HydrationBacklog;
use App\Services\AI\RecapPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Kicks off the connected monthly-recap chain for every completed month whose
 * recap is not Done — the scheduled 1st-of-month sweep and the one-shot kickoff
 * that follows a first-connect backfill draw from this single query.
 *
 * A month that closed before the athlete connected Strava is filled
 * rule-based up front, the same as a month past the backfill depth cap —
 * Temari was not there for it.
 *
 * @see docs/decisions/deferred-recap-windowing.md
 */
class KickoffMonthlyRecaps
{
    public function __construct(
        private readonly AnalysisService $service,
        private readonly BackfillAgeGate $ages,
        private readonly RecentlyActiveUsers $activeUsers,
        private readonly HydrationBacklog $backlog,
    ) {
    }

    /**
     * @param  int|null  $userId  narrow to one user; null sweeps every active athlete
     * @return array{dispatched: int, rule_based: int}
     */
    public function __invoke(?int $userId = null): array
    {
        // The latest fully-closed month (last month). The current, still-running
        // month is excluded so a recap never narrates an incomplete month.
        $lastClosedMonth = RecapPeriod::lastClosedMonth();
        $oldestRealMonth = $this->ages->cutoffMonth();

        $stagger = (int) config('ai.backfill_stagger_seconds', 360);

        $userIds = $this->activeUsers->query()
            ->when($userId !== null, fn (Builder $query): Builder => $query->whereKey($userId))
            ->pluck('id');

        $dispatched = 0;
        $ruleFilled = 0;
        foreach ($userIds as $id) {
            $months = $this->completedMonthsNotDone((int) $id, $lastClosedMonth);

            // Months older than the backfill depth cap never get a real LLM
            // call — rule-based fill instead, same as the per-activity cap.
            $tooOld = $months->filter(fn (string $month): bool => $month < $oldestRealMonth)->values();
            $narratable = $months->reject(fn (string $month): bool => $month < $oldestRealMonth)->values();

            // A month that closed before the athlete connected Strava is
            // history Temari never watched — filled rule-based too.
            $connectedAt = $this->backlog->connectedAt((int) $id);
            $preConnect = $narratable->filter(fn (string $month): bool => $this->closedBeforeConnect($month, $connectedAt))->values();
            $narratable = $narratable->reject(fn (string $month): bool => $this->closedBeforeConnect($month, $connectedAt))->values();

            // A month still hydrating stages Pending instead of rule-based; the
            // hourly self-heal sweep resumes it once the drain empties.
            $hydrationSplit = $narratable
                ->partition(fn (string $month): bool => $this->backlog->monthAwaitsHydration((int) $id, $month));
            $stillHydrating = $hydrationSplit->get(0, new Collection())->values();
            $narratable = $hydrationSplit->get(1, new Collection())->values();
            $stillHydrating->each(fn (string $month) => $this->service->requestDeferred(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: (int) $id,
                type: AnalysisType::MonthlyRecap,
                discriminator: $month,
            ));

            $tooOld->each(fn (string $month) => $this->service->requestRuleBased(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: (int) $id,
                type: AnalysisType::MonthlyRecap,
                discriminator: $month,
            ));
            $preConnect->each(fn (string $month) => $this->service->requestRuleBased(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: (int) $id,
                type: AnalysisType::MonthlyRecap,
                discriminator: $month,
            ));
            $ruleFilled += $tooOld->count() + $preConnect->count();

            // Oldest month first so the connected story narrates in chronological
            // order: the kickoff dispatches the earliest link and the job chain
            // (AnalyzeMonthlyRecapJob) walks forward to each successor once its
            // predecessor is Done. invalidate:false never re-bills a Done recap,
            // so this doubles as a daily resume safety net for stalled links.
            $narratable->each(function (string $month, int $index) use ($id, $stagger): void {
                $this->service->request(
                    subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                    subjectId: (int) $id,
                    type: AnalysisType::MonthlyRecap,
                    discriminator: $month,
                    delaySeconds: $index * $stagger,
                    invalidate: false,
                );
            });

            $dispatched += $narratable->count();
        }

        return ['dispatched' => $dispatched, 'rule_based' => $ruleFilled];
    }

    /**
     * Whether $month (Y-m) was already over before the athlete's Strava
     * connection landed — the same anchor {@see \App\Services\AI\HistoryNarrationGate} uses.
     */
    private function closedBeforeConnect(string $month, ?Carbon $connectedAt): bool
    {
        return $connectedAt !== null
            && Carbon::parse($month.'-01')->endOfMonth()->endOfDay()->lt($connectedAt);
    }

    /**
     * The user's completed months (Y-m, <= $lastClosedMonth, with runs) whose
     * MonthlyRecap is not yet Done, oldest first. Done months are skipped so the
     * kickoff never re-bills a finished recap.
     *
     * @return Collection<int, string>
     */
    private function completedMonthsNotDone(int $userId, string $lastClosedMonth): Collection
    {
        $months = ActivityDetail::query()
            ->whereHas('activity', fn ($query) => $query->where('user_id', $userId))
            ->whereNotNull('start_date_local')
            ->selectRaw("DISTINCT DATE_FORMAT(start_date_local, '%Y-%m') as month")
            ->orderBy('month')
            ->pluck('month')
            ->filter(fn (string $month): bool => $month <= $lastClosedMonth)
            ->values();

        $doneMonths = Analysis::query()
            ->where('subject_type', AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE)
            ->where('subject_id', $userId)
            ->where('analysis_type', AnalysisType::MonthlyRecap)
            ->where('status', AnalysisStatus::Done)
            ->pluck('discriminator')
            ->all();

        return $months->reject(fn (string $month): bool => in_array($month, $doneMonths, strict: true))->values();
    }
}
