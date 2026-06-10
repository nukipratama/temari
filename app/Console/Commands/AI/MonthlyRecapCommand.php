<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Models\Activity;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

#[Signature('ai:monthly-recap')]
#[Description('Dispatch monthly recap narration for last month (one LLM call per active user, after the month closes)')]
class MonthlyRecapCommand extends Command
{
    public function handle(AnalysisService $service): int
    {
        $start = Carbon::today()->subMonthNoOverflow()->startOfMonth();
        $month = $start->format('Y-m');

        $activeUserIds = Activity::query()
            ->whereHas('detail', fn ($query) => $query
                ->whereBetween('start_date_local', [$start, $start->copy()->endOfMonth()]))
            ->distinct()
            ->pluck('user_id');

        foreach ($activeUserIds as $userId) {
            $service->request(
                subjectOrType: AnalysisType::MONTHLY_RECAP_SUBJECT_TYPE,
                subjectId: (int) $userId,
                type: AnalysisType::MonthlyRecap,
                discriminator: $month,
                invalidate: true,
            );
        }

        $this->info("Dispatched monthly recap for {$activeUserIds->count()} active users ({$month}).");

        return self::SUCCESS;
    }
}
