<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\AI\Analysis;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Support\Carbon;

/**
 * Creates the kickoff rows a missed scheduler minute never created, and nothing
 * else: the kickoffs' own creation paths run under
 * {@see AnalysisService::withoutDispatching()}, which reduces each `request()`
 * to its `firstOrCreate`.
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
        $before = Analysis::query()->count();

        $this->service->withoutDispatching(function (): void {
            $today = Carbon::today()->toDateString();
            $isoWeek = AnalysisType::currentIsoWeek();

            foreach (($this->activeUsers)() as $user) {
                $this->service->requestBriefing($user, $today);

                $this->service->request(
                    subjectOrType: AnalysisType::ProfileVoice->subjectType(),
                    subjectId: $user->id,
                    type: AnalysisType::ProfileVoice,
                    discriminator: $isoWeek,
                );
            }

            ($this->weeklyRecaps)();
        });

        return Analysis::query()->count() - $before;
    }
}
