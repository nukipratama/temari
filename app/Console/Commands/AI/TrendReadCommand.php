<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Signature('ai:trend-read {range : One of AnalysisType::TREND_READ_RANGES (7d/30d/90d/12mo)}')]
#[Description('Dispatch the Trends tab narration for one range (7d/30d/90d/12mo), one cadence per range — see routes/console.php')]
class TrendReadCommand extends Command
{
    public function handle(AnalysisService $service, RecentlyActiveUsers $activeUsers): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $range = (string) $this->argument('range');
        if (! in_array($range, AnalysisType::TREND_READ_RANGES, true)) {
            $this->error('range must be one of: '.implode(', ', AnalysisType::TREND_READ_RANGES));

            return self::FAILURE;
        }

        $users = $activeUsers();

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
