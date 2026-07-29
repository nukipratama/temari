<?php

declare(strict_types=1);

namespace App\Services\AI\Agent;

use App\Exceptions\AI\ContentFilterException;
use App\Services\AI\AzureConfigCircuitBreaker;
use App\Services\AI\AzureFailureMapper;
use App\Services\AI\AzureOpenAIClient;
use Illuminate\Support\Facades\Log;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Responses\Responses\Output\OutputFunctionToolCall;
use Throwable;

/**
 * The request side of a narration call: one Responses API turn, and the
 * multi-step tool-calling run built out of them. Every turn it completes is
 * folded into the caller's {@see AgentBudget} as it happens, so a run that
 * throws mid-loop still leaves the turns it burned on the budget.
 */
final readonly class AgentLoop
{
    public function __construct(
        private AzureOpenAIClient $azure,
        private AzureConfigCircuitBreaker $configBreaker,
    ) {
    }

    /**
     * Take the model's turn until it stops asking for tools, and return both the
     * answering response and the conversation that produced it.
     *
     * Without a toolbox this is one request, which is every narrator that has
     * not been given tools yet.
     *
     * Termination is guaranteed twice over: the budget forbids tools once a
     * ceiling is hit, and a turn that was already forbidden them is returned
     * whatever it says. A capped run answers from what it managed to read
     * rather than failing — the user gets narration either way.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: CreateResponse, 1: list<array<string, mixed>>}
     */
    public function converse(
        string $kind,
        array $payload,
        ?AgentToolbox $toolbox,
        AgentBudget $budget,
        float $startedAt,
    ): array {
        /** @var list<array<string, mixed>> $input */
        $input = $payload['input'];

        if ($toolbox === null) {
            return [$this->createResponse($kind, $payload, $budget, $startedAt), $input];
        }

        while (true) {
            $toolsAllowed = $budget->allowsToolStep();
            $payload['input'] = $input;
            $payload['tool_choice'] = $toolsAllowed ? 'auto' : 'none';

            $response = $this->createResponse($kind, $payload, $budget, $startedAt);

            $calls = self::functionCalls($response);
            if ($calls === [] || ! $toolsAllowed) {
                return [$response, $input];
            }

            foreach ($calls as $call) {
                $input[] = $call->toArray();
                $input[] = [
                    'type' => 'function_call_output',
                    'call_id' => $call->callId,
                    'output' => $toolbox->invoke($call->name, $call->arguments),
                ];
            }

            Log::info('narrator.ai.tool_step', [
                'kind' => $kind,
                'step' => $budget->steps(),
                'tools' => array_map(fn (OutputFunctionToolCall $call): string => $call->name, $calls),
            ]);

            if (! $budget->allowsToolStep()) {
                Log::warning('narrator.ai.agent_capped', [
                    'kind' => $kind,
                    'reason' => $budget->exhaustedReason(),
                    'steps' => $budget->steps(),
                    'total_tokens' => $budget->totalTokens(),
                ]);
            }
        }
    }

    /**
     * Replay the run so it must answer now: the conversation so far, a raised
     * token cap, and no further tools.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $input
     */
    public function forceAnswer(
        string $kind,
        array $payload,
        array $input,
        int $maxTokens,
        AgentBudget $budget,
        float $startedAt,
    ): CreateResponse {
        return $this->createResponse($kind, self::forcedAnswerPayload($payload, $input, $maxTokens), $budget, $startedAt);
    }

    /**
     * Issue one Responses API request, folding its usage into the budget and
     * mapping any Azure failure into the caller's transient/terminal exception
     * taxonomy.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createResponse(string $kind, array $payload, AgentBudget $budget, float $startedAt): CreateResponse
    {
        try {
            $response = $this->azure->client()->responses()->create($payload);
        } catch (Throwable $e) {
            Log::warning('narrator.ai.call', [
                'kind' => $kind,
                'status' => 'fail',
                'error' => $e->getMessage(),
                'latency_ms' => self::latencyMs($startedAt),
            ]);

            // A wrong API key (401/403) or wrong base URL/host (DNS/connection)
            // is a config/auth failure: count it toward the Azure config breaker
            // so a persistent misconfig trips and generation pauses cleanly (rows
            // stay Pending) instead of burning the retry budget on every row.
            if (AzureFailureMapper::isConfigAuthFailure($e)) {
                $this->configBreaker->recordFailure();
            }

            throw AzureFailureMapper::map($e);
        }

        // The call reached Azure and authenticated, so any prior config-failure
        // streak is stale: reset the breaker (fast no-op when already closed).
        $this->configBreaker->recordSuccess();
        $budget->recordStep(...self::usageOf($response));

        // Output-side filtering returns HTTP 200 with an empty body rather than
        // throwing a content_filter error, so it would otherwise decode as
        // non-JSON and dead-letter. Map it to the same ContentFilterException as
        // input-side so it flows through the strip-retry + rule-based fallback.
        if (AzureFailureMapper::isOutputContentFiltered($response)) {
            throw new ContentFilterException('Azure OpenAI call failed: output filtered by content management policy');
        }

        return $response;
    }

    /**
     * The function calls the model asked for this turn, if any.
     *
     * @return list<OutputFunctionToolCall>
     */
    private static function functionCalls(CreateResponse $response): array
    {
        return array_values(array_filter(
            $response->output,
            fn (object $item): bool => $item instanceof OutputFunctionToolCall,
        ));
    }

    /**
     * The turn's usage, in the order {@see AgentBudget::recordStep()} takes it.
     *
     * The last two are breakdowns the provider reports alongside the totals:
     * cached input (billed at a discount) and reasoning output (billed at the
     * full output rate). Both are absent on responses that carry neither.
     *
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int} input, output, total, cached, reasoning
     */
    private static function usageOf(CreateResponse $response): array
    {
        return [
            (int) ($response->usage->inputTokens ?? 0),
            (int) ($response->usage->outputTokens ?? 0),
            (int) ($response->usage->totalTokens ?? 0),
            (int) ($response->usage->inputTokensDetails->cachedTokens ?? 0),
            (int) ($response->usage->outputTokensDetails->reasoningTokens ?? 0),
        ];
    }

    /**
     * The payload replayed when the run must answer now: the conversation so
     * far, a raised token cap, and no further tools.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<array<string, mixed>>  $input
     * @return array<string, mixed>
     */
    private static function forcedAnswerPayload(array $payload, array $input, int $maxTokens): array
    {
        $payload['input'] = $input;
        $payload['max_output_tokens'] = $maxTokens;
        if (isset($payload['tools'])) {
            $payload['tool_choice'] = 'none';
        }

        return $payload;
    }

    private static function latencyMs(float $startedAt): int
    {
        return (int) ((microtime(true) - $startedAt) * 1000);
    }
}
