<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Enums\ScheduledTaskSkipReason;
use App\Enums\ScheduledTaskStatus;
use App\Models\Analytics\ScheduledTaskRunLog;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use WeakMap;

/**
 * Records a heartbeat for every scheduled command as it finishes or fails — one
 * global listener instead of per-command wiring, so a newly scheduled command
 * shows up on the Pulse SchedulerHealth card without any extra plumbing — and
 * appends every run except the heartbeat's own to {@see ScheduledTaskRunLog}.
 *
 * Registered in {@see \App\Providers\AppServiceProvider::boot()} as a singleton,
 * so a run's log row is closed by the same instance that opened it.
 */
class RecordScheduledTaskRun
{
    private const string HEARTBEAT = 'schedule:heartbeat';

    /** @var WeakMap<Event, ScheduledTaskRunLog> */
    private WeakMap $openLogs;

    public function __construct()
    {
        $this->openLogs = new WeakMap();
    }

    public function starting(ScheduledTaskStarting $event): void
    {
        $command = self::label($event->task);

        if ($command !== self::HEARTBEAT) {
            $this->openLogs[$event->task] = ScheduledTaskRunLog::start($command);
        }
    }

    public function finished(ScheduledTaskFinished $event): void
    {
        ScheduledTaskRun::record(
            self::label($event->task),
            $event->task->getExpression(),
            ScheduledTaskStatus::Ok,
            (int) round($event->runtime * 1000),
        );

        $log = $this->takeOpenLog($event->task);

        if ($log === null) {
            return;
        }

        if ($event->task->skippedBecauseOverlapping) {
            $log->skipOverlapping();

            return;
        }

        $exitCode = $event->task->exitCode;

        $log->close(
            $exitCode === null || $exitCode === 0 ? ScheduledTaskStatus::Ok : ScheduledTaskStatus::Failed,
            $exitCode,
            (int) round($event->runtime * 1000),
        );
    }

    public function failed(ScheduledTaskFailed $event): void
    {
        ScheduledTaskRun::record(
            self::label($event->task),
            $event->task->getExpression(),
            ScheduledTaskStatus::Failed,
            failureMessage: $event->exception->getMessage(),
        );

        $this->takeOpenLog($event->task)?->close(ScheduledTaskStatus::Failed, $event->task->exitCode, null);
    }

    public function skipped(ScheduledTaskSkipped $event): void
    {
        $command = self::label($event->task);

        if ($command === self::HEARTBEAT) {
            return;
        }

        $paused = Schedule::$pausable
            && ! $event->task->runsWhenPaused()
            && Cache::get('illuminate:schedule:paused', false);

        ScheduledTaskRunLog::skip($command, $paused ? ScheduledTaskSkipReason::Paused : ScheduledTaskSkipReason::Gate);
    }

    /**
     * Prefer the artisan command and its arguments (e.g. "ai:trend-read 7d") parsed
     * from the built command string, so commands that share a signature but differ
     * only by argument stay distinguishable; fall back to Laravel's display summary
     * for anything that isn't a plain artisan command (e.g. a scheduled closure).
     * Public so the Pulse scheduler timeline keys live Schedule events the same way
     * the recorded rows were keyed.
     */
    public static function label(Event $task): string
    {
        if (preg_match("/\\bartisan'?\\s+(.+)$/", (string) $task->command, $matches) === 1) {
            return trim($matches[1]);
        }

        return $task->getSummaryForDisplay();
    }

    private function takeOpenLog(Event $task): ?ScheduledTaskRunLog
    {
        $log = $this->openLogs[$task] ?? null;
        unset($this->openLogs[$task]);

        return $log;
    }
}
