<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScheduledTaskStatus;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Override;
use Throwable;

/**
 * Heartbeat for one scheduled command: when it last ran, whether it succeeded,
 * how long it took. Upserted by {@see \App\Listeners\RecordScheduledTaskRun} and
 * surfaced on the Pulse SchedulerHealth card.
 *
 * @property int $id
 * @property string $command
 * @property string|null $expression
 * @property ScheduledTaskStatus $last_status
 * @property Carbon|null $last_run_at
 * @property int|null $runtime_ms
 * @property string|null $failure_message
 */
#[Fillable([
    'command',
    'expression',
    'last_status',
    'last_run_at',
    'runtime_ms',
    'failure_message',
])]
class ScheduledTaskRun extends Model
{
    /**
     * Upsert the heartbeat for a command, stamping last_run_at to now.
     */
    public static function record(
        string $command,
        ?string $expression,
        ScheduledTaskStatus $status,
        ?int $runtimeMs = null,
        ?string $failureMessage = null,
    ): self {
        return self::query()->updateOrCreate(
            ['command' => $command],
            [
                'expression' => $expression,
                'last_status' => $status,
                'last_run_at' => Carbon::now(),
                'runtime_ms' => $runtimeMs,
                'failure_message' => $failureMessage,
            ],
        );
    }

    public function hasFailed(): bool
    {
        return $this->last_status === ScheduledTaskStatus::Failed;
    }

    /**
     * Late if the command hasn't run within ~2x its nominal cadence — derived
     * from the cron expression so daily/hourly/5-min commands each get their own
     * threshold. Returns false when we can't tell (no expression, no run yet, or
     * an unparseable expression) so a missing signal never reads as an alert.
     */
    public function isStale(): bool
    {
        if ($this->expression === null || $this->last_run_at === null) {
            return false;
        }

        try {
            $cron = new CronExpression($this->expression);
            $next = Carbon::instance($cron->getNextRunDate($this->last_run_at));
        } catch (Throwable) {
            return false;
        }

        $intervalSec = (int) abs($this->last_run_at->diffInSeconds($next));
        if ($intervalSec <= 0) {
            return false;
        }

        return abs(Carbon::now()->diffInSeconds($this->last_run_at)) > 2 * $intervalSec;
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    protected function casts(): array
    {
        return [
            'last_status' => ScheduledTaskStatus::class,
            'last_run_at' => 'datetime',
            'runtime_ms' => 'integer',
        ];
    }
}
