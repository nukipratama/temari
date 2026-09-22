<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\AI\Analysis;
use App\Services\Gamification\StreakSettlementService;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

/**
 * Creates the kickoff rows a missed scheduler minute never created and records
 * the deterministic readiness side effects needed by next-day compliance.
 *
 * The three types here are exactly the per-user kickoff types
 * {@see \App\Services\AI\SelfHealer} already resumes — filling is left to that
 * hourly sweep, so a type it does not sweep would only gain a row that stays
 * empty forever.
 */
class KickoffCatchUp
{
    public function __construct(
        private readonly AnalysisService $service,
        private readonly RecentlyActiveUsers $activeUsers,
        private readonly KickoffWeeklyRecaps $weeklyRecaps,
        private readonly RunDailyBriefingSideEffects $sideEffects,
        private readonly StreakSettlementService $streakSettlement,
    ) {
    }

    /**
     * @return int rows this run brought into existence
     */
    public function __invoke(): int
    {
        $created = 0;

        $this->service->withoutDispatching(function () use (&$created): void {
            $today = Carbon::today()->toDateString();
            $isoWeek = AnalysisType::currentIsoWeek();

            foreach (($this->activeUsers)() as $user) {
                ($this->sideEffects)($user, Carbon::today());
                $created += (int) $this->service->requestBriefing($user, $today)->wasRecentlyCreated;
                $created += (int) $this->service->requestProfileVoice($user, $isoWeek)->wasRecentlyCreated;
            }

            // The scheduler-chain flag is date-scoped; keep the durable query
            // so a deferred recap can resume after Monday.
            if ($this->streakSettlement->allUsersSettled()) {
                $recapsBefore = $this->recapRowCount();
                ($this->weeklyRecaps)();
                $created += $this->recapRowCount() - $recapsBefore;
            }
        });

        return $created;
    }

    private function recapRowCount(): int
    {
        return Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->count();
    }
}
