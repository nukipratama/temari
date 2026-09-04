<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\Activity;
use App\Models\User;
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
    /**
     * How recently a user must have run to be briefed, keyed off the run's own
     * date because an on-connect backfill stamps `analyzed_at` to now across a
     * whole imported history.
     */
    private const int ACTIVE_WINDOW_DAYS = 7;

    public function handle(AnalysisService $service): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $today = Carbon::today()->toDateString();

        $activeUserIds = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activity_details.start_date_local', '>=', Carbon::today()->subDays(self::ACTIVE_WINDOW_DAYS))
            ->whereIn('activities.user_id', User::query()->notDemo()->select('id'))
            ->distinct()
            ->pluck('activities.user_id');

        $users = User::query()->whereIn('id', $activeUserIds)->get();

        foreach ($users as $user) {
            $service->requestBriefing($user, $today);
        }

        $this->info("Dispatched daily kickoff (briefing) for {$users->count()} active users.");

        return self::SUCCESS;
    }
}
