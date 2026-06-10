<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\TokenUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Laravel\Pulse\Facades\Pulse;
use Throwable;

class TokenUsageRecorder
{
    public function record(
        string $kind,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        ?string $model,
        ?int $latencyMs = null,
        bool $truncated = false,
        ?int $userId = null,
    ): void {
        try {
            TokenUsage::query()->create([
                'user_id' => $userId,
                'kind' => $kind,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
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

        // Feed the /pulse AI Pipeline card's per-kind token trend. Same
        // never-throw guarantee as the DB insert, and independent of it so a
        // hiccup in one sink doesn't drop the other.
        try {
            Pulse::record('ai_tokens', $kind, $totalTokens)->sum();
        } catch (Throwable $e) {
            Log::warning('token_usage.pulse_record_failed', [
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
