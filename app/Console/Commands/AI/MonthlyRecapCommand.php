<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\RecapPeriod;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('ai:monthly-recap')]
#[Description('Kick off the connected monthly-recap chain: narrate every completed month whose recap is not Done, oldest first (demo excluded)')]
class MonthlyRecapCommand extends Command
{
    public function handle(AnalysisService $service): int
    {
        // The latest fully-closed month (last month). The current, still-running
        // month is excluded so a recap never narrates an incomplete month.
        $lastClosedMonth = RecapPeriod::lastClosedMonth();

        $stagger = (int) config('ai.backfill_stagger_seconds', 360);

        // Demo never auto-bills any LLM cadence: its content is the rule-based
        // seed, so every recap chain excludes it (locked decision).
        $userIds = User::query()->notDemo()->pluck('id');

        $dispatched = 0;
        foreach ($userIds as $userId) {
            $months = $this->completedMonthsNotDone((int) $userId, $lastClosedMonth);

            // Oldest month first so the connected story narrates in chronological
            // order: the kickoff dispatches the earliest link and the job chain
            // (AnalyzeMonthlyRecapJob) walks forward to each successor once its
            // predecessor is Done. invalidate:false never re-bills a Done recap,
            // so this doubles as a daily resume safety net for stalled links.
            $months->each(function (string $month, int $index) use ($service, $userId, $stagger): void {
                $service->request(
                    subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                    subjectId: (int) $userId,
                    type: AnalysisType::MonthlyRecap,
                    discriminator: $month,
                    delaySeconds: $index * $stagger,
                    invalidate: false,
                );
            });

            $dispatched += $months->count();
        }

        $this->info("Dispatched monthly recap for {$dispatched} months (through {$lastClosedMonth}).");

        return self::SUCCESS;
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
