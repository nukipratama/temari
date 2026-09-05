<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\Activity;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Signature('ai:trend-read {range : One of AnalysisType::TREND_READ_RANGES (30d/90d/12mo)}')]
#[Description('Dispatch the Trends tab narration for one range (30d/90d/12mo), one cadence per range — see routes/console.php')]
class TrendReadCommand extends Command
{
    /**
     * How recently a user must have run to be worth narrating a trend for —
     * same window and same "keyed off the run's own date, not analyzed_at"
     * reasoning as DailyBriefingCommand, so a dormant account doesn't burn a
     * scheduled LLM call narrating a range with nothing in it.
     */
    private const int ACTIVE_WINDOW_DAYS = 7;

    public function handle(AnalysisService $service): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $range = (string) $this->argument('range');
        if (! in_array($range, AnalysisType::TREND_READ_RANGES, true)) {
            $this->error('range must be one of: '.implode(', ', AnalysisType::TREND_READ_RANGES));

            return self::FAILURE;
        }

        $activeUserIds = Activity::query()
            ->join('activity_details', 'activity_details.activity_id', '=', 'activities.id')
            ->where('activity_details.start_date_local', '>=', Carbon::today()->subDays(self::ACTIVE_WINDOW_DAYS))
            ->whereIn('activities.user_id', User::query()->notDemo()->select('id'))
            ->distinct()
            ->pluck('activities.user_id');

        $users = User::query()->whereIn('id', $activeUserIds)->get();

        foreach ($users as $user) {
            $service->request(
                subjectOrType: AnalysisType::TrendRead->subjectType(),
                subjectId: $user->id,
                type: AnalysisType::TrendRead,
                discriminator: $range,
            );
        }

        $this->info("Dispatched trend read ({$range}) for {$users->count()} active users.");

        return self::SUCCESS;
    }
}
