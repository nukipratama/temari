<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AI\AnalysisVersion;
use App\Models\AI\TokenUsage;
use App\Models\Analytics\DevtoolsAction;
use App\Models\Analytics\ScheduledTaskRunLog;
use App\Models\Analytics\StravaRead;
use App\Models\Analytics\StravaSyncLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Prunes the metering and audit tables no other retention bounds: the
 * analytics-connection ones (`ai_token_usages`, `strava_reads`,
 * `strava_sync_logs`, `devtools_actions`, `scheduled_task_run_logs`) and the
 * superseded-narration history (`analysis_versions`, app connection) — unlike `failed_jobs`, which
 * `queue:prune-failed` already bounds.
 */
#[Signature('analytics:prune')]
#[Description('Delete metering, audit and narration-version rows older than 90 days')]
class PruneAnalyticsCommand extends Command
{
    private const int RETENTION_DAYS = 90;

    private const int CHUNK_SIZE = 1000;

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays(self::RETENTION_DAYS);

        $tokenUsages = $this->prune(TokenUsage::query()->where('created_at', '<', $cutoff));
        $stravaReads = $this->prune(StravaRead::query()->where('read_at', '<', $cutoff));
        $syncLogs = $this->prune(StravaSyncLog::query()->where('synced_at', '<', $cutoff));
        $versions = $this->prune(AnalysisVersion::query()->where('created_at', '<', $cutoff));
        $devtoolsActions = $this->prune(DevtoolsAction::query()->where('created_at', '<', $cutoff));
        $runLogs = $this->prune(ScheduledTaskRunLog::query()->where('started_at', '<', $cutoff));

        $this->info(sprintf(
            'Pruned %d ai_token_usages, %d strava_reads, %d strava_sync_logs, %d analysis_versions, %d devtools_actions and %d scheduled_task_run_logs row(s) older than %d days.',
            $tokenUsages,
            $stravaReads,
            $syncLogs,
            $versions,
            $devtoolsActions,
            $runLogs,
            self::RETENTION_DAYS,
        ));

        return self::SUCCESS;
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $expired
     */
    private function prune(Builder $expired): int
    {
        $total = 0;

        do {
            $deleted = (clone $expired)->limit(self::CHUNK_SIZE)->delete();
            $total += $deleted;
        } while ($deleted === self::CHUNK_SIZE);

        return $total;
    }
}
