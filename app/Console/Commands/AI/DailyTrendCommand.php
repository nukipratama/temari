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

#[Signature('ai:daily-trend')]
#[Description('Dispatch trend caption analysis for each active user (last 7 days)')]
class DailyTrendCommand extends Command
{
    public function handle(AnalysisService $service): int
    {
        $cutoff = Carbon::today()->subDays(7);
        $today = Carbon::today()->toDateString();

        $activeUserIds = Activity::query()
            ->where('analyzed_at', '>=', $cutoff)
            ->distinct()
            ->pluck('user_id')
            ->all();

        $count = 0;
        foreach ($activeUserIds as $userId) {
            $userIdInt = (int) $userId;
            if (! User::query()->whereKey($userIdInt)->exists()) {
                continue;
            }

            $service->request(
                subjectOrType: AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
                subjectId: $userIdInt,
                type: AnalysisType::TrendCaption,
                discriminator: $today,
                force: true,
            );
            $count++;
        }

        $this->info("Dispatched trend caption analysis for {$count} active users.");

        return self::SUCCESS;
    }
}
