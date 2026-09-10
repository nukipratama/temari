<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AI\AnalysisVersion;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\DevtoolsAction;
use App\Models\Analytics\StravaSyncLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Prunes the metering and audit tables no other retention bounds: the
 * analytics-connection ones (`ai_token_usages`, `strava_sync_logs`,
 * `devtools_actions`) and the superseded-narration history
 * (`analysis_versions`, app connection) — unlike `failed_jobs`, which
 * `queue:prune-failed` already bounds.
 */
#[Signature('analytics:prune')]
#[Description('Delete metering, audit and narration-version rows older than 90 days')]
class PruneAnalyticsCommand extends Command
{
    private const int RETENTION_DAYS = 90;

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);

        $tokenUsages = TokenUsage::query()->where('created_at', '<', $cutoff)->delete();
        $syncLogs = StravaSyncLog::query()->where('synced_at', '<', $cutoff)->delete();
        $versions = AnalysisVersion::query()->where('created_at', '<', $cutoff)->delete();
        $devtoolsActions = DevtoolsAction::query()->where('created_at', '<', $cutoff)->delete();

        $this->info(sprintf(
            'Pruned %d ai_token_usages, %d strava_sync_logs, %d analysis_versions and %d devtools_actions row(s) older than %d days.',
            $tokenUsages,
            $syncLogs,
            $versions,
            $devtoolsActions,
            self::RETENTION_DAYS,
        ));

        return self::SUCCESS;
    }
}
