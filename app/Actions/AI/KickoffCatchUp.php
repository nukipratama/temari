<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

/**
 * Creates the kickoff rows a missed scheduler minute never created, and nothing
 * else. See docs/decisions/kickoff-catch-up-is-upsert-only.md.
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
                $created += (int) $this->service->requestBriefing($user, $today)->wasRecentlyCreated;
                $created += (int) $this->service->requestProfileVoice($user, $isoWeek)->wasRecentlyCreated;
            }

            $recapsBefore = $this->recapRowCount();
            ($this->weeklyRecaps)();
            $created += $this->recapRowCount() - $recapsBefore;
        });

        return $created;
    }

    private function recapRowCount(): int
    {
        return Analysis::query()->where('analysis_type', AnalysisType::WeeklyRecap)->count();
    }
}
