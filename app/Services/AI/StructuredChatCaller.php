<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use App\Services\AI\Agent\AgentBudget;
use App\Services\AI\Agent\AgentToolbox;
use App\Services\AI\Narrators\NarratorContinuity;
use Illuminate\Support\Facades\Log;
use JsonException;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Responses\Responses\CreateResponse;
use OpenAI\Responses\Responses\Output\OutputFunctionToolCall;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Thin shared wrapper around the Azure OpenAI Responses API call. Handles the
 * request, structured-JSON decoding, logging, and exception mapping that every
 * narrator otherwise duplicates. The Responses surface (vs chat completions) is
 * what serves both chat and reasoning/codex deployments.
 *
 * With a toolbox on the options the same call becomes an agent run: the model
 * is offered reads and keeps its turn until it stops asking for them, bounded
 * by {@see AgentBudget}. Every guarantee a one-shot call carries — the
 * content-filter strip-retry, the truncation retry, the exception taxonomy,
 * metering — holds across the whole run, and the metering row sums it.
 */
final readonly class StructuredChatCaller
{
    /**
     * Hard ceiling for the truncation retry's bumped token cap, so a runaway
     * schema can't balloon a single block's cost.
     */
    private const int MAX_RETRY_OUTPUT_TOKENS = 4000;

    public function __construct(
        private AzureOpenAIClient $azure,
        private TokenUsageRecorder $usageRecorder,
        private AzureConfigCircuitBreaker $configBreaker,
    ) {
    }

    /**
     * @param  list<string>  $requiredKeys
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function call(
        string $kind,
        string $systemPrompt,
        array $context,
        string $schemaName,
        array $requiredKeys,
        ?ChatCallOptions $options = null,
    ): array {
        $options ??= new ChatCallOptions();
        $startedAt = microtime(true);
        $effectiveMaxTokens = $options->maxTokens ?? (int) config('azure_openai.max_completion_tokens');
        $deployment = $this->azure->deploymentFor($kind);
        $toolbox = $options->toolbox !== null && ! $options->toolbox->isEmpty() ? $options->toolbox : null;
        $budget = AgentBudget::fromConfig($options->maxSteps);

        $payload = [
            'model' => $deployment,
            'input' => [
                ['role' => 'system', 'content' => TemariPersona::systemPrompt()."\n\n".$systemPrompt],
                ['role' => 'user', 'content' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ],
            'max_output_tokens' => $effectiveMaxTokens,
            'temperature' => $options->temperature,
            // Keyed on the narrator, never the user. The cacheable prefix is the
            // persona plus this narrator's prompt and tool schemas, which is
            // byte-identical for everyone who calls it — keying per user would
            // shard that one prefix across the whole user base and make the hit
            // rate worse, not better. Azure scopes its cache per deployment
            // already, so this must not encode a model either.
            'prompt_cache_key' => $kind,
            'text' => ['format' => self::textFormat($schemaName, $requiredKeys)],
        ];

        if ($toolbox !== null) {
            $payload['tools'] = $toolbox->definitions();
        }

        // Everything below can throw after tokens have already been billed: a
        // transient 5xx on turn five, a non-JSON answer, a missing key. The meter
        // therefore runs in a finally. Losing those turns understated spend
        // exactly when the job's retries were multiplying it, and the daily cost
        // ceiling reads the table it writes.
        $response = null;

        try {
            try {
                [$response, $input] = $this->converse($kind, $payload, $toolbox, $budget, $startedAt);
            } catch (ContentFilterException $e) {
                // A stored continuity line fed back as input can trip Azure's filter.
                // Strip the continuity keys and retry once against the clean context;
                // if the retry still content-filters, let it propagate. The retry
                // restarts the conversation from the two base messages — a filtered
                // run's tool outputs are not worth replaying — but keeps the budget,
                // since the tokens it already burned were still billed.
                $strippedKeys = array_values(array_intersect(NarratorContinuity::CONTEXT_KEYS, array_keys($context)));
                if ($strippedKeys === []) {
                    throw $e;
                }

                $context = array_diff_key($context, array_flip(NarratorContinuity::CONTEXT_KEYS));
                $payload['input'][1]['content'] = json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

                Log::info('narrator.ai.content_filter_retry', [
                    'kind' => $kind,
                    'stripped_keys' => $strippedKeys,
                ]);

                [$response, $input] = $this->converse($kind, $payload, $toolbox, $budget, $startedAt);
            }

            // Truncated structured output is unparseable, so retry once at a higher
            // token cap, bounded by self::MAX_RETRY_OUTPUT_TOKENS. The retry replays
            // the conversation as it stands and forbids further tools, so the extra
            // budget buys the answer rather than another read.
            if (self::isTruncated($response)) {
                $retryMaxTokens = min((int) ceil($effectiveMaxTokens * 1.5), self::MAX_RETRY_OUTPUT_TOKENS);
                if ($retryMaxTokens > $effectiveMaxTokens) {
                    Log::warning('narrator.ai.truncated_retry', [
                        'kind' => $kind,
                        'max_output_tokens' => $effectiveMaxTokens,
                        'retry_max_output_tokens' => $retryMaxTokens,
                    ]);
                    $effectiveMaxTokens = $retryMaxTokens;
                    $payload = self::forcedAnswerPayload($payload, $input, $effectiveMaxTokens);
                    $response = $this->createResponse($kind, $payload, $startedAt);
                    $budget->recordStep(...self::usageOf($response));
                }
            }

            $decoded = $this->decoded($response, $requiredKeys);
            $truncated = self::isTruncated($response);

            if ($truncated) {
                Log::warning('narrator.ai.truncated', [
                    'kind' => $kind,
                    'output_tokens' => $budget->outputTokens(),
                    'max_output_tokens' => $effectiveMaxTokens,
                ]);
            }

            Log::info('narrator.ai.call', [
                'kind' => $kind,
                'status' => 'ok',
                'latency_ms' => self::latencyMs($startedAt),
                'truncated' => $truncated,
                'steps' => $budget->steps(),
                'usage' => [
                    'input' => $budget->inputTokens(),
                    'output' => $budget->outputTokens(),
                    'total' => $budget->totalTokens(),
                    'cached' => $budget->cachedTokens(),
                    'reasoning' => $budget->reasoningTokens(),
                ],
            ]);

            return $decoded;
        } finally {
            $this->meter($kind, $budget, $deployment, $startedAt, $response, $options->userId);
        }
    }

    /**
     * The structured payload the run produced, or a terminal failure explaining
     * why it could not be read.
     *
     * @param  list<string>  $requiredKeys
     * @return array<string, mixed>
     */
    private function decoded(CreateResponse $response, array $requiredKeys): array
    {
        try {
            $decoded = json_decode((string) ($response->outputText ?? ''), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnavailableException('Azure OpenAI returned non-JSON: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new UnavailableException('Azure OpenAI structured output not an object');
        }

        $missingLabel = $this->missingKeyLabel($decoded, $requiredKeys);
        if ($missingLabel !== null) {
            throw new UnavailableException("Azure OpenAI structured output missing {$missingLabel}");
        }

        return $decoded;
    }

    /**
     * Write the run's usage: the whole run's, not the last turn's, since a tool
     * loop bills every step and so does each retry.
     *
     * Called from a finally, so it also fires when the run threw after burning
     * turns. A run with no completed turn has nothing to report and is skipped.
     */
    private function meter(
        string $kind,
        AgentBudget $budget,
        string $deployment,
        float $startedAt,
        ?CreateResponse $response,
        ?int $userId,
    ): void {
        if ($budget->steps() === 0) {
            return;
        }

        $this->usageRecorder->record(
            kind: $kind,
            usage: $budget,
            model: $deployment !== '' ? $deployment : null,
            latencyMs: self::latencyMs($startedAt),
            truncated: $response !== null && self::isTruncated($response),
            userId: $userId,
        );
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
    private function converse(
        string $kind,
        array $payload,
        ?AgentToolbox $toolbox,
        AgentBudget $budget,
        float $startedAt,
    ): array {
        /** @var list<array<string, mixed>> $input */
        $input = $payload['input'];

        if ($toolbox === null) {
            $response = $this->createResponse($kind, $payload, $startedAt);
            $budget->recordStep(...self::usageOf($response));

            return [$response, $input];
        }

        while (true) {
            $toolsAllowed = $budget->allowsToolStep();
            $payload['input'] = $input;
            $payload['tool_choice'] = $toolsAllowed ? 'auto' : 'none';

            $response = $this->createResponse($kind, $payload, $startedAt);
            $budget->recordStep(...self::usageOf($response));

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

    /**
     * Issue one Responses API request, mapping any Azure failure into the
     * caller's transient/terminal exception taxonomy.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createResponse(string $kind, array $payload, float $startedAt): CreateResponse
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
            if (self::isConfigAuthFailure($e)) {
                $this->configBreaker->recordFailure();
            }

            throw self::mapAzureThrowable($e);
        }

        // The call reached Azure and authenticated, so any prior config-failure
        // streak is stale: reset the breaker (fast no-op when already closed).
        $this->configBreaker->recordSuccess();

        // Output-side filtering returns HTTP 200 with an empty body rather than
        // throwing a content_filter error, so it would otherwise decode as
        // non-JSON and dead-letter. Map it to the same ContentFilterException as
        // input-side so it flows through the strip-retry + rule-based fallback.
        if (self::isOutputContentFiltered($response)) {
            throw new ContentFilterException('Azure OpenAI call failed: output filtered by content management policy');
        }

        return $response;
    }

    /**
     * Whether $e is an Azure *config/auth* failure: a permanent 401/403 (wrong
     * API key / deployment access) or a connection/DNS/timeout failure (wrong
     * base URL/host). These feed the config circuit breaker; a single one is
     * still transient, the breaker's consecutive-failure streak is what
     * distinguishes a persistent misconfig from a one-off blip.
     */
    private static function isConfigAuthFailure(Throwable $e): bool
    {
        if ($e instanceof ErrorException && in_array($e->getStatusCode(), [401, 403], true)) {
            return true;
        }

        return $e instanceof TransporterException;
    }

    /**
     * Classify an Azure OpenAI throwable. Rate-limit (429), server error (5xx),
     * and connection/timeout failures are transient and should let the queue
     * retry; everything else is terminal and fails the row.
     */
    private static function mapAzureThrowable(Throwable $e): Throwable
    {
        $message = 'Azure OpenAI call failed: '.$e->getMessage();

        // A content-filter rejection is an input-driven terminal 400: retrying
        // the same prompt just re-trips the filter. Surface the distinct type so
        // the caller can strip continuity context and retry, and the job can
        // degrade to rule-based content instead of dead-lettering.
        if (self::isContentFilter($e)) {
            return new ContentFilterException($message, previous: $e);
        }

        $response = self::transientResponse($e);

        if ($response === false) {
            return new UnavailableException($message, previous: $e);
        }

        return new TransientUpstreamException(
            $message,
            $response !== null ? self::retryAfterSeconds($response) : null,
            $e,
        );
    }

    /**
     * Whether $e is an Azure content-filter rejection. Detected primarily by the
     * error code (`content_filter`), with a defensive substring fallback on the
     * message for the prose forms Azure sometimes returns without the code.
     */
    private static function isContentFilter(Throwable $e): bool
    {
        if (! $e instanceof ErrorException) {
            return false;
        }

        if ($e->getErrorCode() === 'content_filter') {
            return true;
        }

        $message = strtolower($e->getMessage());

        return str_contains($message, 'content management policy')
            || str_contains($message, 'content_filter');
    }

    /**
     * Resolve whether $e is a transient upstream failure, returning its HTTP
     * response (for `Retry-After`), `null` when transient but response-less
     * (connection/timeout), or `false` when the failure is terminal.
     */
    private static function transientResponse(Throwable $e): ResponseInterface|null|false
    {
        if ($e instanceof RateLimitException || $e instanceof ServerException) {
            return $e->response;
        }

        if ($e instanceof ErrorException && ($e->getStatusCode() === 429 || $e->getStatusCode() >= 500)) {
            return $e->response;
        }

        // TransporterException = connection refused / DNS / read timeout: transient
        // but response-less. Anything else is a terminal (permanent) failure.
        return $e instanceof TransporterException ? null : false;
    }

    /**
     * Read Azure's `Retry-After` header (delta-seconds form) if present.
     */
    private static function retryAfterSeconds(ResponseInterface $response): ?int
    {
        $header = trim($response->getHeaderLine('Retry-After'));
        if ($header === '' || ! ctype_digit($header)) {
            return null;
        }

        return (int) $header;
    }

    private static function isTruncated(CreateResponse $response): bool
    {
        return $response->status === 'incomplete'
            && $response->incompleteDetails?->reason === 'max_output_tokens';
    }

    /**
     * Whether Azure filtered the *output*: a 200 response marked incomplete with
     * an explicit content_filter reason, the output-side twin of the thrown
     * input-side content_filter 400.
     */
    private static function isOutputContentFiltered(CreateResponse $response): bool
    {
        return $response->status === 'incomplete'
            && $response->incompleteDetails?->reason === 'content_filter';
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<string>  $requiredKeys
     */
    private function missingKeyLabel(array $decoded, array $requiredKeys): ?string
    {
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (! isset($decoded[$key]) || ! is_string($decoded[$key])) {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return null;
        }

        return count($requiredKeys) === 1 ? $missing[0] : 'required fields';
    }

    /**
     * The Responses API structured-output format (text.format): json_schema with
     * the fields flattened, unlike chat completions' nested `json_schema` wrapper.
     *
     * @param  list<string>  $requiredKeys
     * @return array{type: string, name: string, strict: bool, schema: array<string, mixed>}
     */
    private static function textFormat(string $schemaName, array $requiredKeys): array
    {
        $properties = [];
        foreach ($requiredKeys as $key) {
            $properties[$key] = ['type' => 'string'];
        }

        return [
            'type' => 'json_schema',
            'name' => $schemaName,
            'strict' => true,
            'schema' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => $properties,
                'required' => $requiredKeys,
            ],
        ];
    }

    private static function latencyMs(float $startedAt): int
    {
        return (int) ((microtime(true) - $startedAt) * 1000);
    }
}
