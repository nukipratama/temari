<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Agent\AgentToolbox;

/**
 * Bundle of optional per-call knobs for {@see StructuredChatCaller::call()}.
 * Kept as a value object so the caller's parameter list stays under the
 * sonar 7-param threshold without losing call-site readability.
 *
 * `maxTokens` null = use the global `azure_openai.max_completion_tokens`
 * config default. Override per-narrator when the schema can produce longer
 * output (Cerita lari, Weekly recap, etc.) to avoid truncated responses.
 *
 * `toolbox` null = one-shot call. Supplying one turns the call into an agent
 * run: the model reads what it decides it needs before answering.
 */
final readonly class ChatCallOptions
{
    public function __construct(
        public float $temperature = 0.8,
        public ?int $userId = null,
        public ?int $maxTokens = null,
        public ?AgentToolbox $toolbox = null,
    ) {
    }
}
