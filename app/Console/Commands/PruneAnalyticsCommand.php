<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AI\TokenUsage;
use App\Models\Analytics\StravaSyncLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prunes the analytics-connection metering tables (`ai_token_usages`,
 * `strava_sync_logs`), neither of which had any retention before this —
 * unlike `failed_jobs`, which `queue:prune-failed` already bounds.
 */
#[Signature('analytics:prune')]
#[Description('Delete ai_token_usages and strava_sync_logs rows older than 90 days')]
class PruneAnalyticsCommand extends Command
{
    private const int RETENTION_DAYS = 90;

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);

        $tokenUsages = TokenUsage::query()->where('created_at', '<', $cutoff)->delete();
        $syncLogs = StravaSyncLog::query()->where('synced_at', '<', $cutoff)->delete();

        $this->info(sprintf(
            'Pruned %d ai_token_usages row(s) and %d strava_sync_logs row(s) older than %d days.',
            $tokenUsages,
            $syncLogs,
            self::RETENTION_DAYS,
        ));

        return self::SUCCESS;
    }
}
