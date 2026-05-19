<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Models\AI\TokenUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

class TokenUsageRecorder
{
    public function record(
        string $kind,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        ?string $model,
    ): void {
        try {
            TokenUsage::query()->create([
                'kind' => $kind,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'model' => $model,
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
