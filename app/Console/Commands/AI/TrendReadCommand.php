<?php

declare(strict_types=1);

namespace App\Console\Commands\AI;

use App\Actions\AI\RecentlyActiveUsers;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use App\Services\AI\HistoryNarrationGate;
use App\Services\AI\TrendReadFingerprint;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;

#[Signature('ai:trend-read {range : One of AnalysisType::TREND_READ_RANGES (7d)}')]
#[Description('Dispatch the Trends tab verdict — see routes/console.php')]
class TrendReadCommand extends Command
{
    public function handle(AnalysisService $service, RecentlyActiveUsers $activeUsers, TrendReadFingerprint $fingerprint, HistoryNarrationGate $history): int
    {
        app(NarrationOrigin::class)->set(AnalysisOrigin::Scheduled);

        $range = (string) $this->argument('range');
        if (! in_array($range, AnalysisType::TREND_READ_RANGES, true)) {
            $this->error('range must be one of: '.implode(', ', AnalysisType::TREND_READ_RANGES));

            return self::FAILURE;
        }

        $users = $activeUsers();

        foreach ($users as $user) {
            // A load/fitness/form read of a backlog still hydrating (a fresh
            // connect) — the same hold the backfill-time request gets.
            if ($history->awaitsFullHydration($user->id)) {
                continue;
            }

            $service->request(
                subjectOrType: AnalysisType::TrendRead->subjectType(),
                subjectId: $user->id,
                type: AnalysisType::TrendRead,
                discriminator: $range,
                invalidate: $fingerprint->changed($user, $range),
            );
        }

        $this->info("Dispatched trend read ({$range}) for {$users->count()} active users.");

        return self::SUCCESS;
    }

}
