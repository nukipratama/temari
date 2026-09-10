<?php

declare(strict_types=1);

namespace App\Livewire\Pulse;

use App\Jobs\AI\AnalyzeBaseJob;
use App\Livewire\Pulse\Concerns\SumsPulseTotals;
use App\Models\AI\Analysis;
use App\Models\AI\TokenUsage;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\LlmCostCalculator;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Database\Query\Builder;
use Illuminate\Queue\RedisQueue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\View;
use Laravel\Pulse\Livewire\Card;
use Throwable;

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

    public function render(AnalysisService $analyses, LlmCostCalculator $cost): Renderable
    {
        $statusCounts = Analysis::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $recentFailures = Analysis::query()
            ->where('status', AnalysisStatus::Failed->value)
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get(['subject_type', 'subject_id', 'analysis_type', 'error', 'updated_at']);

        [$trend, $time, $runAt] = $this->remember(fn (): array => [
            'failures' => $this->asCount($this->aggregateTotal('ai_failure', 'count')),
            'contentFilterFallbacks' => $this->asCount($this->aggregateTotal('ai_content_filter_fallback', 'count')),
        ]);

        $failed = (int) ($statusCounts[AnalysisStatus::Failed->value] ?? 0);
        $deadLettered = Analysis::query()->deadLettered()->count();
        $failedJobs = DB::table('failed_jobs')->count();
        $pauseReason = $analyses->pauseReason();

        $statusBoxes = [
            ['label' => 'pending',     'count' => (int) ($statusCounts[AnalysisStatus::Pending->value] ?? 0),    'alert' => false],
            ['label' => 'in progress', 'count' => (int) ($statusCounts[AnalysisStatus::Queued->value] ?? 0) + (int) ($statusCounts[AnalysisStatus::Processing->value] ?? 0), 'alert' => false],
            ['label' => 'done',        'count' => (int) ($statusCounts[AnalysisStatus::Done->value] ?? 0),        'alert' => false],
            ['label' => 'failed',      'count' => $failed,                                                         'alert' => $failed > 0],
        ];

        $spend = $this->spend($cost);
        $latency = $this->latency();
        $queue = $this->queueSnapshot();

        return View::make('livewire.pulse.ai-pipeline-health', [
            'cols' => $this->cols,
            'rows' => $this->rows,
            'class' => $this->class,
            'time' => $time,
            'runAt' => $runAt,
            'statusBoxes' => $statusBoxes,
            'recentFailures' => $recentFailures,
            'trend' => $trend,
            'deadLettered' => $deadLettered,
            'failedJobs' => $failedJobs,
            'pauseReason' => $pauseReason,
            'spend' => $spend,
            'latency' => $latency,
            'queue' => $queue,
            'severity' => match (true) {
                $failed > 0 => 'alert',
                $pauseReason !== null => 'warn',
                default => 'ok',
            },
        ]);
    }

    /**
     * Today's LLM spend against both stops: the app-wide total, and the
     * per-athlete ceiling shown as the heaviest athlete today measured against
     * it (the ceiling binds per athlete, so the app total never reveals it).
     *
     * @return array{today: float, totalCeiling: float|null, topAthlete: float, perUserCeiling: float|null, cappedAthletes: int}
     */
    private function spend(LlmCostCalculator $cost): array
    {
        $perUserCeiling = config('azure_openai.daily_cost_ceiling_per_user');
        $perUserCeiling = $perUserCeiling === null ? null : (float) $perUserCeiling;
        $totalCeiling = config('azure_openai.daily_cost_ceiling_total');

        $rows = TokenUsage::query()->toBase()
            ->whereBetween('created_at', [Carbon::today()->startOfDay(), Carbon::today()->endOfDay()])
            ->whereNotNull('user_id')
            ->selectRaw('user_id, model, SUM(prompt_tokens) as prompt, SUM(completion_tokens) as completion, SUM(cached_tokens) as cached')
            ->groupBy('user_id', 'model')
            ->get();

        $perAthlete = [];
        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $perAthlete[$userId] = ($perAthlete[$userId] ?? 0.0)
                + $cost->costFor((string) $row->model, (int) $row->prompt, (int) $row->completion, (int) $row->cached);
        }

        return [
            'today' => $cost->dailyCost(),
            'totalCeiling' => $totalCeiling === null ? null : (float) $totalCeiling,
            'topAthlete' => $perAthlete === [] ? 0.0 : max($perAthlete),
            'perUserCeiling' => $perUserCeiling,
            'cappedAthletes' => $perUserCeiling === null
                ? 0
                : count(array_filter($perAthlete, fn (float $spent): bool => $spent >= $perUserCeiling)),
        ];
    }

    /**
     * Azure round-trip latency over the card's range, from the `latency_ms` each
     * usage row already carries. Percentiles are picked by offset over the
     * ordered rows: MySQL has no PERCENTILE_CONT, and Pulse's own
     * slow_outgoing_requests never sees these calls.
     *
     * @return array{p50: int|null, p95: int|null, calls: int}
     */
    private function latency(): array
    {
        $base = TokenUsage::query()->toBase()
            ->where('created_at', '>=', Carbon::now()->sub($this->periodAsInterval()))
            ->whereNotNull('latency_ms');

        $calls = (clone $base)->count();

        return [
            'p50' => $calls === 0 ? null : $this->latencyAt($base, $calls, 0.5),
            'p95' => $calls === 0 ? null : $this->latencyAt($base, $calls, 0.95),
            'calls' => $calls,
        ];
    }

    private function latencyAt(Builder $base, int $calls, float $quantile): int
    {
        return (int) (clone $base)
            ->orderBy('latency_ms')
            ->offset((int) floor($quantile * ($calls - 1)))
            ->limit(1)
            ->value('latency_ms');
    }

    /**
     * Depth of the `ai` queue plus the age of the job at its head. The age comes
     * from Horizon's `pushedAt` payload stamp, so it stays null on any driver
     * but redis.
     *
     * @return array{depth: int, oldestSeconds: int|null}
     */
    private function queueSnapshot(): array
    {
        try {
            $connection = Queue::connection();
            $depth = (int) $connection->size(AnalyzeBaseJob::QUEUE);
        } catch (Throwable) {
            return ['depth' => 0, 'oldestSeconds' => null];
        }

        return [
            'depth' => $depth,
            'oldestSeconds' => $connection instanceof RedisQueue ? $this->oldestQueuedSeconds($connection) : null,
        ];
    }

    private function oldestQueuedSeconds(RedisQueue $connection): ?int
    {
        try {
            $payload = $connection->getConnection()->lindex('queues:'.AnalyzeBaseJob::QUEUE, 0);
        } catch (Throwable) {
            return null;
        }

        $decoded = is_string($payload) ? json_decode($payload, true) : null;
        $pushedAt = is_array($decoded) ? ($decoded['pushedAt'] ?? null) : null;

        if (! is_numeric($pushedAt)) {
            return null;
        }

        return max(0, (int) (Carbon::now()->getTimestamp() - (float) $pushedAt));
    }
}
