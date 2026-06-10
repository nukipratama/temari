<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Models\ScheduledTaskRun;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * Scheduler heartbeat on the /pulse dashboard: the last run, status, and runtime
 * of every scheduled command (strava:sync, strava:ingest, the AI recaps...),
 * with a "late" flag when a command hasn't run within ~2x its cadence. Fills the
 * blind spot where a stalled scheduler on the shared homelab was invisible.
 *
 * Fed by {@see \App\Listeners\RecordScheduledTaskRun}. Not lazy: a single small
 * table scan, so deferring buys nothing.
 */
class SchedulerHealth extends Card
{
    public function render(): Renderable
    {
        $tasks = ScheduledTaskRun::query()
            ->orderBy('command')
            ->get()
            ->map(fn (ScheduledTaskRun $task): array => [
                'command' => $task->command,
                // Single presentation state so the blade switches once instead of
                // re-deriving the failed/stale/ok precedence for both ring + badge.
                'status' => match (true) {
                    $task->hasFailed() => 'failed',
                    $task->isStale() => 'late',
                    default => 'ok',
                },
                'lastRunAt' => $task->last_run_at,
                'runtimeMs' => $task->runtime_ms,
                'failureMessage' => $task->failure_message,
            ]);

        return View::make('livewire.pulse.scheduler-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'tasks' => $tasks,
        ]);
    }
}
