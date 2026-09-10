<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Console\SchedulerChain;
use App\Listeners\RecordScheduledTaskRun;
use App\Models\ScheduledTaskRun;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Throwable;

/**
 * Scheduler timeline on the /pulse dashboard: every registered command in
 * next-due order with its last run, runtime and Monday-chain prerequisites,
 * plus any recorded command no longer on the schedule. Driven by the live
 * Schedule so a command that has never run is visible rather than absent, and
 * by {@see RecordScheduledTaskRun} for what actually happened.
 *
 * Not lazy: one small table scan plus in-memory cron maths, so deferring buys
 * nothing.
 */
class SchedulerHealth extends Card
{
    public function render(Schedule $schedule): Renderable
    {
        $runs = ScheduledTaskRun::query()->get()->keyBy('command');

        $scheduled = collect($schedule->events())
            ->map(function (Event $event) use ($runs): array {
                $command = RecordScheduledTaskRun::label($event);

                return $this->row($command, $runs->get($command), $this->nextDue($event));
            })
            ->sortBy(fn (array $task): string => $task['nextDue']?->toDateTimeString() ?? '9999')
            ->values();

        $retired = $runs
            ->reject(fn (ScheduledTaskRun $run): bool => $scheduled->contains('command', $run->command))
            ->map(fn (ScheduledTaskRun $run): array => $this->row($run->command, $run, null))
            ->sortBy('command')
            ->values();

        return View::make('livewire.pulse.scheduler-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'tasks' => $scheduled->concat($retired),
        ]);
    }

    /**
     * @return array{command: string, status: string, lastRunAt: Carbon|null, runtimeMs: int|null, failureMessage: string|null, nextDue: Carbon|null, prerequisites: list<array{command: string, met: bool}>}
     */
    private function row(string $command, ?ScheduledTaskRun $run, ?Carbon $nextDue): array
    {
        return [
            'command' => $command,
            'status' => match (true) {
                $run === null => 'never run',
                $run->hasFailed() => 'failed',
                $run->isStale() => 'late',
                default => 'ok',
            },
            'lastRunAt' => $run?->last_run_at,
            'runtimeMs' => $run?->runtime_ms,
            'failureMessage' => $run?->failure_message,
            'nextDue' => $nextDue,
            'prerequisites' => $this->prerequisites($command),
        ];
    }

    /**
     * @return list<array{command: string, met: bool}>
     */
    private function prerequisites(string $command): array
    {
        return array_map(
            fn (string $prerequisite): array => [
                'command' => $prerequisite,
                'met' => SchedulerChain::isDoneToday($prerequisite),
            ],
            SchedulerChain::prerequisitesFor($command),
        );
    }

    private function nextDue(Event $event): ?Carbon
    {
        try {
            return Carbon::instance($event->nextRunDate());
        } catch (Throwable) {
            return null;
        }
    }
}
