<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Livewire\Pulse\Concerns\SumsPulseTotals;
use App\Services\AI\AnalysisStatus;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;

/**
 * AI narration pipeline health, on the /pulse dashboard: a live status snapshot
 * of the ai_analyses rows, the most recent failures with their stored error,
 * and the failure-rate trend recorded in AnalysisService::markFailed().
 *
 * Not lazy: it sits at the top of the dashboard (always in the initial
 * viewport) and its queries are cheap, so deferring buys nothing.
 */
class AiPipelineHealth extends Card
{
    use SumsPulseTotals;

    public function render(): Renderable
    {
        $statusCounts = DB::table('ai_analyses')
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $recentFailures = DB::table('ai_analyses')
            ->where('status', AnalysisStatus::Failed->value)
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get(['subject_type', 'subject_id', 'analysis_type', 'error', 'updated_at']);

        [$trend, $time, $runAt] = $this->remember(fn (): array => [
            'failures' => $this->asCount($this->aggregateTotal('ai_failure', 'count')),
        ]);

        $failed = (int) ($statusCounts[AnalysisStatus::Failed->value] ?? 0);

        $statusBoxes = [
            ['label' => 'pending',     'count' => (int) ($statusCounts[AnalysisStatus::Pending->value] ?? 0),    'alert' => false],
            ['label' => 'in progress', 'count' => (int) ($statusCounts[AnalysisStatus::Queued->value] ?? 0) + (int) ($statusCounts[AnalysisStatus::Processing->value] ?? 0), 'alert' => false],
            ['label' => 'done',        'count' => (int) ($statusCounts[AnalysisStatus::Done->value] ?? 0),        'alert' => false],
            ['label' => 'failed',      'count' => $failed,                                                         'alert' => $failed > 0],
        ];

        return View::make('livewire.pulse.ai-pipeline-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'time' => $time,
            'runAt' => $runAt,
            'statusBoxes' => $statusBoxes,
            'recentFailures' => $recentFailures,
            'trend' => $trend,
            'severity' => $failed > 0 ? 'alert' : 'ok',
        ]);
    }
}
