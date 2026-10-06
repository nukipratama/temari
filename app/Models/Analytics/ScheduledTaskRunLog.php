<?php

declare(strict_types=1);

namespace App\Models\Analytics;

use App\Enums\ScheduledTaskSkipReason;
use App\Enums\ScheduledTaskStatus;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;

/**
 * One row per scheduled run, written by {@see \App\Listeners\RecordScheduledTaskRun}.
 * A row still `running` past its entry's overlap lock reads as killed.
 *
 * @property int $id
 * @property string $command
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property int|null $runtime_ms
 * @property ScheduledTaskStatus $status
 * @property int|null $exit_code
 * @property ScheduledTaskSkipReason|null $skipped_reason
 */
#[Fillable(['command', 'started_at', 'finished_at', 'runtime_ms', 'status', 'exit_code', 'skipped_reason'])]
#[Connection('analytics')]
#[Table(name: 'scheduled_task_run_logs')]
#[WithoutTimestamps]
class ScheduledTaskRunLog extends Model
{
    public const int STATS_DAYS = 30;

    public const int DEFAULT_WINDOW_SECONDS = 1440 * 60;

    public static function start(string $command): self
    {
        return self::query()->create([
            'command' => $command,
            'started_at' => Carbon::now(),
            'status' => ScheduledTaskStatus::Running,
        ]);
    }

    public static function skip(string $command, ScheduledTaskSkipReason $reason): self
    {
        $now = Carbon::now();

        return self::query()->create([
            'command' => $command,
            'started_at' => $now,
            'finished_at' => $now,
            'status' => ScheduledTaskStatus::Skipped,
            'skipped_reason' => $reason,
        ]);
    }

    public function close(ScheduledTaskStatus $status, ?int $exitCode, ?int $runtimeMs): void
    {
        $now = Carbon::now();

        $this->update([
            'finished_at' => $now,
            'runtime_ms' => $runtimeMs ?? (int) abs($now->diffInMilliseconds($this->started_at)),
            'status' => $status,
            'exit_code' => $exitCode,
        ]);
    }

    public function skipOverlapping(): void
    {
        $this->update([
            'finished_at' => Carbon::now(),
            'status' => ScheduledTaskStatus::Skipped,
            'skipped_reason' => ScheduledTaskSkipReason::Overlapping,
        ]);
    }

    /** How long a run of $event may stay open before it reads as killed: its overlap lock's lifetime. */
    public static function windowSeconds(Event $event): int
    {
        return $event->expiresAt * 60;
    }

    /**
     * Per command over the last {@see self::STATS_DAYS} days: runs, failures, skips,
     * killed runs and the p50/p95/max runtime of successful runs (nearest rank),
     * plus whether the latest run is the killed one.
     *
     * @param  array<string, int>  $windows  command => {@see self::windowSeconds()}
     * @return array<string, array{runs: int, failures: int, skips: int, killed: int, p50: int|null, p95: int|null, max: int|null, latestKilled: bool}>
     */
    public static function stats(array $windows): array
    {
        $now = Carbon::now();
        $cutoffs = [];
        $killedCutoff = '?';

        if ($windows !== []) {
            $killedCutoff = 'CASE command'.str_repeat(' WHEN ? THEN ?', count($windows)).' ELSE ? END';

            foreach ($windows as $command => $seconds) {
                array_push($cutoffs, $command, $now->copy()->subSeconds($seconds));
            }
        }

        $cutoffs[] = $now->copy()->subSeconds(self::DEFAULT_WINDOW_SECONDS);

        $ranked = self::query()->toBase()
            ->select(['command', 'status', 'started_at', 'runtime_ms'])
            ->selectRaw("status = 'running' AND started_at < {$killedCutoff} AS killed", $cutoffs)
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY command, status ORDER BY runtime_ms) AS runtime_rank')
            ->selectRaw("SUM(status = 'ok') OVER (PARTITION BY command) AS ok_count")
            ->where('started_at', '>=', $now->copy()->subDays(self::STATS_DAYS));

        $rows = self::query()->toBase()
            ->fromSub($ranked, 'ranked')
            ->select('command')
            ->selectRaw("SUM(status <> 'skipped') AS runs")
            ->selectRaw("SUM(status = 'failed') AS failures")
            ->selectRaw("SUM(status = 'skipped') AS skips")
            ->selectRaw('SUM(killed) AS killed')
            ->selectRaw("MIN(CASE WHEN status = 'ok' AND runtime_rank >= CEIL(0.5 * ok_count) THEN runtime_ms END) AS p50")
            ->selectRaw("MIN(CASE WHEN status = 'ok' AND runtime_rank >= CEIL(0.95 * ok_count) THEN runtime_ms END) AS p95")
            ->selectRaw("MAX(CASE WHEN status = 'ok' THEN runtime_ms END) AS max_ms")
            ->selectRaw('MAX(started_at) = MAX(CASE WHEN killed THEN started_at END) AS latest_killed')
            ->groupBy('command')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $stats[(string) $row->command] = [
                'runs' => (int) $row->runs,
                'failures' => (int) $row->failures,
                'skips' => (int) $row->skips,
                'killed' => (int) $row->killed,
                'p50' => is_numeric($row->p50) ? (int) $row->p50 : null,
                'p95' => is_numeric($row->p95) ? (int) $row->p95 : null,
                'max' => is_numeric($row->max_ms) ? (int) $row->max_ms : null,
                'latestKilled' => (bool) $row->latest_killed,
            ];
        }

        return $stats;
    }

    /** @return array<string, string> */
    #[Override]
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'runtime_ms' => 'integer',
            'status' => ScheduledTaskStatus::class,
            'exit_code' => 'integer',
            'skipped_reason' => ScheduledTaskSkipReason::class,
        ];
    }
}
