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
        $today = Carbon::today()->toDateString();

        $activeUserIds = Activity::query()
            ->where('analyzed_at', '>=', Carbon::today()->subDays(7))
            ->whereIn('user_id', User::query()->select('id'))
            ->distinct()
            ->pluck('user_id');

        foreach ($activeUserIds as $userId) {
            $service->request(
                subjectOrType: AnalysisType::TREND_CAPTION_SUBJECT_TYPE,
                subjectId: (int) $userId,
                type: AnalysisType::TrendCaption,
                discriminator: $today,
                invalidate: true,
            );
        }

        $this->info("Dispatched trend caption analysis for {$activeUserIds->count()} active users.");

        return self::SUCCESS;
    }
}
