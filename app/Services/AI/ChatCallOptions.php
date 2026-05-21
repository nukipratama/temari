<?php

declare(strict_types=1);

namespace App\Services\AI;

/**
 * Bundle of optional per-call knobs for {@see StructuredChatCaller::call()}.
 * Kept as a value object so the caller's parameter list stays under the
 * sonar 7-param threshold without losing call-site readability.
 *
 * `maxTokens` null = use the global `azure_openai.max_completion_tokens`
 * config default. Override per-narrator when the schema can produce longer
 * output (Cerita lari, Weekly recap, etc.) to avoid truncated responses.
 */
final readonly class ChatCallOptions
{
    public function __construct(
        public float $temperature = 0.8,
        public ?int $userId = null,
        public ?int $maxTokens = null,
    ) {
    }
}
