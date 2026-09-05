<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\BackfillAgeGate;
use App\Services\AI\RecapPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Kicks off the connected monthly-recap chain for every completed month whose
 * recap is not Done — the scheduled 1st-of-month sweep and the one-shot kickoff
 * that follows a first-connect backfill draw from this single query.
 */
class KickoffMonthlyRecaps
{
    public function __construct(
        private readonly AnalysisService $service,
        private readonly BackfillAgeGate $ages,
    ) {
    }

    /**
     * @param  int|null  $userId  narrow to one user; null sweeps every non-demo user
     * @return array{dispatched: int, rule_based: int}
     */
    public function __invoke(?int $userId = null): array
    {
        // The latest fully-closed month (last month). The current, still-running
        // month is excluded so a recap never narrates an incomplete month.
        $lastClosedMonth = RecapPeriod::lastClosedMonth();
        $oldestRealMonth = $this->ages->cutoffMonth();

        $stagger = (int) config('ai.backfill_stagger_seconds', 360);

        // Demo never auto-bills any LLM cadence: its content is the rule-based
        // seed, so every recap chain excludes it (locked decision).
        $userIds = User::query()
            ->notDemo()
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

            $tooOld->each(fn (string $month) => $this->service->requestRuleBased(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: (int) $id,
                type: AnalysisType::MonthlyRecap,
                discriminator: $month,
            ));
            $ruleFilled += $tooOld->count();

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
