<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Scheduling\Event;

/**
 * Records a heartbeat for every scheduled command as it finishes or fails — one
 * global listener instead of per-command wiring, so a newly scheduled command
 * shows up on the Pulse SchedulerHealth card without any extra plumbing.
 *
 * Registered in {@see \App\Providers\AppServiceProvider::boot()}.
 */
class RecordScheduledTaskRun
{
    public function finished(ScheduledTaskFinished $event): void
    {
        ScheduledTaskRun::record(
            self::label($event->task),
            $event->task->getExpression(),
            ScheduledTaskRun::STATUS_OK,
            (int) round($event->runtime * 1000),
        );
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        ScheduledTaskRun::record(
            self::label($event->task),
            $event->task->getExpression(),
            ScheduledTaskRun::STATUS_FAILED,
            failureMessage: $event->exception->getMessage(),
        );
    }

    /**
     * Prefer the bare artisan command name (e.g. "strava:sync") parsed from the
     * built command string; fall back to Laravel's display summary for anything
     * that isn't a plain artisan command (e.g. a scheduled closure).
     */
    private static function label(Event $task): string
    {
        if (preg_match("/\\bartisan'?\\s+([^\\s']+)/", (string) $task->command, $matches) === 1) {
            return $matches[1];
        }

        return $task->getSummaryForDisplay();
    }
}
