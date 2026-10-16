<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Enums\IntervalsIcuSyncStatus;
use App\Livewire\Pulse\Concerns\SumsPulseTotals;
use App\Models\IntervalsIcuConnection;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * intervals.icu sync health on the /pulse dashboard: working and broken
 * connections, where each athlete's workout for today stands, and the sync's
 * sends, updates, removals and failures over the period.
 *
 * Not lazy: two small queries on a table with one row per connected athlete.
 */
class IntervalsIcuHealth extends Card
{
    use SumsPulseTotals;

    private const array COUNTERS = ['created', 'updated', 'deleted', 'failed', 'broken'];

    public function render(): Renderable
    {
        $connections = IntervalsIcuConnection::query()
            ->selectRaw('SUM(CASE WHEN broken_at IS NULL THEN 1 ELSE 0 END) AS working')
            ->selectRaw('SUM(CASE WHEN broken_at IS NOT NULL THEN 1 ELSE 0 END) AS broken')
            ->first();

        $today = IntervalsIcuConnection::query()
            ->whereNull('broken_at')
            ->whereDate('synced_on', Carbon::today())
            ->whereNotNull('sync_status')
            ->selectRaw('sync_status, COUNT(*) AS total')
            ->groupBy('sync_status')
            ->pluck('total', 'sync_status');

        [$counts, $time, $runAt] = $this->remember(fn (): array => $this->aggregate('intervals_icu_sync', 'count')
            ->pluck('count', 'key')
            ->all());

        $trends = [];
        foreach (self::COUNTERS as $counter) {
            $trends[$counter] = $this->asCount($counts[$counter] ?? 0);
        }

        $states = [
            'working' => (int) ($connections->working ?? 0),
            'broken' => (int) ($connections->broken ?? 0),
            'sent' => (int) ($today[IntervalsIcuSyncStatus::Sent->value] ?? 0),
            'not_sent' => (int) ($today[IntervalsIcuSyncStatus::NoThresholdHeartRate->value] ?? 0),
            'failing' => (int) ($today[IntervalsIcuSyncStatus::Unreachable->value] ?? 0),
        ];

        return View::make('livewire.pulse.intervals-icu-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'time' => $time,
            'runAt' => $runAt,
            'states' => $states,
            'trends' => $trends,
            'severity' => match (true) {
                $states['broken'] > 0 || $states['failing'] > 0 => 'alert',
                $states['not_sent'] > 0 || $trends['failed'] > 0 => 'warn',
                default => 'ok',
            },
        ]);
    }
}
