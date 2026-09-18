<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\RestClampRecorder;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\HistoryNarrationGate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Signature('ai:daily-briefing')]
#[Description('Dispatch the daily briefing set for each active user (last 7 days)')]
class DailyBriefingCommand extends Command
{
    public function handle(
        AnalysisService $service,
        RestClampRecorder $restClampRecorder,
        PlanNarrationRequester $planNarration,
        RecentlyActiveUsers $activeUsers,
        HistoryNarrationGate $history,
    ): int {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $today = Carbon::today()->toDateString();

        $users = $activeUsers();

        foreach ($users as $user) {
            // The other place today's readiness ceiling is computed. Covers a
            // clamp that fires on carried-over fatigue, with no run to trigger
            // the ingest listener.
            $restClampRecorder->record($user, Carbon::today());
            // Covers a clamp that fires on carried-over fatigue with no run
            // behind it, which the ingest listener never sees.
            $planNarration->requestClampVoice($user, Carbon::today());

            // A first connect late enough in the day that its drain crosses
            // this 00:01 kickoff must not have this cadence write the same
            // thin briefing DispatchPostRunAnalysis already holds — see
            // HistoryNarrationGate::awaitsOlderHydration(). Held means staged
            // Pending, not skipped; ai:self-heal releases it once history lands.
            if ($history->awaitsOlderHydration($user->id, Carbon::now())) {
                $service->requestDeferred(AnalysisType::BRIEFING_SUBJECT_TYPE, $user->id, AnalysisType::BriefingMascotVoice, $today);

                continue;
            }

            $service->requestBriefing($user, $today);
        }

        $this->info("Dispatched daily kickoff (briefing) for {$users->count()} active users.");

        return self::SUCCESS;
    }
}
