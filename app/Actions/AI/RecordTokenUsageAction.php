<?php

declare(strict_types=1);

namespace App\Actions\AI;

use App\Models\AI\TokenUsage;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\Agent\AgentBudget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordTokenUsageAction
{
    /**
     * Write one row for one call. `$usage` carries the whole run's totals, which
     * for an agent means every turn of the tool loop plus any retry, not just
     * the turn that produced the answer.
     */
    public function __invoke(
        string $kind,
        AgentBudget $usage,
        ?string $model,
        ?int $latencyMs = null,
        bool $truncated = false,
        ?int $userId = null,
        AnalysisOrigin $origin = AnalysisOrigin::Unknown,
    ): void {
        try {
            TokenUsage::query()->create([
                'user_id' => $userId,
                'kind' => $kind,
                'origin' => $origin,
                // The usage table's prompt/completion columns hold input/output.
                'prompt_tokens' => $usage->inputTokens(),
                'completion_tokens' => $usage->outputTokens(),
                'total_tokens' => $usage->totalTokens(),
                'cached_tokens' => $usage->cachedTokens(),
                'reasoning_tokens' => $usage->reasoningTokens(),
                'steps' => $usage->steps(),
                'model' => $model,
                'latency_ms' => $latencyMs,
                'truncated' => $truncated,
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            // Metering must never tank the job after a successful Azure call —
            // otherwise the retry would double-bill.
            Log::warning('token_usage.record_failed', [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
