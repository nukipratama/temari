<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Services\AI\Agent\AgentToolbox;
use Closure;

/**
 * Bundle of optional per-call knobs for {@see StructuredChatCaller::call()}.
 *
 * - `temperature` null = send no `temperature`, for a deployment that rejects
 *   or ignores one.
 * - `maxTokens` null = the global `azure_openai.max_completion_tokens` default.
 * - `toolbox` null = one-shot call; supplying one turns the call into an agent
 *   run.
 * - `maxSteps` null = the global `ai.agent.max_steps` default; an override must
 *   allow `2 * (tools + 1)` turns or the content-filter retry answers with no
 *   readings.
 * - `deadlineSeconds` null = the global `ai.agent.deadline_seconds` default;
 *   pass a shorter value when the call shares a larger job-wide deadline.
 * - `validator` null = the required keys are the whole contract; supplying one
 *   receives the decoded payload and returns null to accept, or the corrective
 *   sentence the model is re-asked with.
 */
final readonly class ChatCallOptions
{
    /**
     * @param  (Closure(array<string, mixed>): ?string)|null  $validator
     */
    public function __construct(
        public ?float $temperature = 0.8,
        public ?int $userId = null,
        public ?int $maxTokens = null,
        public ?AgentToolbox $toolbox = null,
        public ?int $maxSteps = null,
        public ?Closure $validator = null,
        public ?int $deadlineSeconds = null,
    ) {
    }
}
