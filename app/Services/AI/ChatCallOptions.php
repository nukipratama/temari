<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Agent\AgentToolbox;

/**
 * Bundle of optional per-call knobs for {@see StructuredChatCaller::call()}.
 * Kept as a value object so the caller's parameter list stays under the
 * sonar 7-param threshold without losing call-site readability.
 *
 * `temperature` null = send no `temperature` at all, for a deployment that
 * rejects or ignores a sampling temperature (the gpt-5-class reasoning tier
 * does). Every kind sends one today, since both configured deployments accept
 * it; opting one out is a sampling change, so it belongs to whoever swaps the
 * deployment, not to a refactor.
 *
 * `maxTokens` null = use the global `azure_openai.max_completion_tokens`
 * config default. Override per-narrator when the schema can produce longer
 * output (the post-run story, weekly recap, etc.) to avoid truncated responses.
 *
 * `toolbox` null = one-shot call. Supplying one turns the call into an agent
 * run: the model reads what it decides it needs before answering.
 *
 * `maxSteps` null = use the global `ai.agent.max_steps` config default, which
 * is sized for the widest toolbox. Every tool takes no arguments and is worth
 * calling once, so one pass needs at most `tools + 1` turns; the content-filter
 * retry replays that pass on the same budget, so an override must allow
 * `2 * (tools + 1)` or the retry answers with no readings at all. Only worth
 * setting where that lands *below* the default.
 */
final readonly class ChatCallOptions
{
    public function __construct(
        public ?float $temperature = 0.8,
        public ?int $userId = null,
        public ?int $maxTokens = null,
        public ?AgentToolbox $toolbox = null,
        public ?int $maxSteps = null,
    ) {
    }
}
