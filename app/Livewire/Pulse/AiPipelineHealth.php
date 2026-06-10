<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Livewire\Pulse\Concerns\SumsPulseTotals;
use App\Services\AI\AnalysisStatus;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use stdClass;

/**
 * AI narration pipeline health, on the /pulse dashboard: a live status snapshot
 * of the ai_analyses rows, the most recent failures with their stored error,
 * the failure-rate trend recorded in AnalysisService::markFailed(), and the
 * per-kind LLM token spend recorded in TokenUsageRecorder.
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
            ->get(['subject_type', 'subject_id', 'analysis_type', 'attempts', 'error', 'updated_at']);

        [$trend, $time, $runAt] = $this->remember(function (): array {
            /** @var Collection<int, stdClass> $tokenRows */
            $tokenRows = $this->aggregate('ai_tokens', 'sum');

            $tokensPerKind = $tokenRows
                ->map(fn (stdClass $row): array => [
                    'kind' => (string) $row->key,
                    'tokens' => (int) $row->sum,
                ])
                ->values()
                ->all();

            return [
                'failures' => $this->asCount($this->aggregateTotal('ai_failure', 'count')),
                'tokensPerKind' => $tokensPerKind,
                'tokensTotal' => array_sum(array_column($tokensPerKind, 'tokens')),
            ];
        });

        return View::make('livewire.pulse.ai-pipeline-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'time' => $time,
            'runAt' => $runAt,
            'statuses' => collect(AnalysisStatus::cases())
                ->mapWithKeys(fn (AnalysisStatus $s): array => [$s->value => (int) ($statusCounts[$s->value] ?? 0)]),
            'recentFailures' => $recentFailures,
            'trend' => $trend,
        ]);
    }
}
