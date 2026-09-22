<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Actions\AI\RunDailyBriefingSideEffects;
use App\Services\AI\AnalysisService;
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
        RunDailyBriefingSideEffects $sideEffects,
        RecentlyActiveUsers $activeUsers,
    ): int {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $today = Carbon::today()->toDateString();

        $users = $activeUsers();

        foreach ($users as $user) {
            ($sideEffects)($user, Carbon::today());

            // A first connect's still-draining backlog narrates right away
            // too; markDone() flags the row for SettleEarlyNarrationAction's
            // replay if it's still early.
            $service->requestBriefing($user, $today);
        }

        $this->info("Dispatched daily kickoff (briefing) for {$users->count()} active users.");

        return self::SUCCESS;
    }
}
