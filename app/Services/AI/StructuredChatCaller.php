<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Actions\AI\RecordTokenUsageAction;
use App\Exceptions\AI\ContentFilterException;
use App\Exceptions\AI\UnavailableException;
use App\Services\AI\Agent\AgentBudget;
use App\Services\AI\Agent\AgentLoop;
use App\Services\AI\Narrators\NarratorContinuity;
use Illuminate\Support\Facades\Log;
use JsonException;
use OpenAI\Responses\Responses\CreateResponse;

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
        private RecordTokenUsageAction $recordUsage,
        private AgentLoop $loop,
        private NarrationOrigin $origin,
    ) {
    }

    /**
     * @param  list<string>  $requiredKeys
     * @param  array<string, mixed>  $context
     * @param  array<string, array<string, mixed>>|null  $propertySchema  Per-key JSON-schema override for a required key whose value is not a plain string (e.g. a list of claim objects). A key absent here defaults to {"type": "string"}.
     * @return array<string, mixed>
     */
    public function call(
        string $kind,
        string $systemPrompt,
        array $context,
        string $schemaName,
        array $requiredKeys,
        ?ChatCallOptions $options = null,
        ?array $propertySchema = null,
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
            // Keyed on the narrator, never the user. The cacheable prefix is the
            // persona plus this narrator's prompt and tool schemas, which is
            // byte-identical for everyone who calls it — keying per user would
            // shard that one prefix across the whole user base and make the hit
            // rate worse, not better. Azure scopes its cache per deployment
            // already, so this must not encode a model either.
            'prompt_cache_key' => $kind,
            'text' => ['format' => self::textFormat($schemaName, $requiredKeys, $propertySchema)],
        ];

        if ($options->temperature !== null) {
            $payload['temperature'] = $options->temperature;
        }

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
                [$response, $input] = $this->loop->converse($kind, $payload, $toolbox, $budget, $startedAt);
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

                [$response, $input] = $this->loop->converse($kind, $payload, $toolbox, $budget, $startedAt);
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
                    $response = $this->loop->forceAnswer($kind, $payload, $input, $effectiveMaxTokens, $budget, $startedAt);
                }
            }

            $decoded = $this->decoded($response, $requiredKeys, $propertySchema);
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
                'status' => NarratorCallStatus::Ok->value,
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
     * @param  array<string, array<string, mixed>>|null  $propertySchema
     * @return array<string, mixed>
     */
    private function decoded(CreateResponse $response, array $requiredKeys, ?array $propertySchema): array
    {
        try {
            $decoded = json_decode((string) ($response->outputText ?? ''), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnavailableException('Azure OpenAI returned non-JSON: '.$e->getMessage());
        }

        if (! is_array($decoded)) {
            throw new UnavailableException('Azure OpenAI structured output not an object');
        }

        $missingLabel = $this->missingKeyLabel($decoded, $requiredKeys, $propertySchema);
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

        ($this->recordUsage)(
            kind: $kind,
            usage: $budget,
            model: $deployment !== '' ? $deployment : null,
            latencyMs: self::latencyMs($startedAt),
            truncated: $response !== null && self::isTruncated($response),
            userId: $userId,
            origin: $this->origin->current(),
        );
    }


    private static function isTruncated(CreateResponse $response): bool
    {
        return $response->status === AzureResponseStatus::Incomplete->value
            && $response->incompleteDetails?->reason === 'max_output_tokens';
    }


    /**
     * @param  array<string, mixed>  $decoded
     * @param  list<string>  $requiredKeys
     * @param  array<string, array<string, mixed>>|null  $propertySchema
     */
    private function missingKeyLabel(array $decoded, array $requiredKeys, ?array $propertySchema): ?string
    {
        $missing = [];
        foreach ($requiredKeys as $key) {
            if (! isset($decoded[$key]) || ! self::matchesPropertyType($decoded[$key], $propertySchema[$key] ?? null)) {
                $missing[] = $key;
            }
        }

        if ($missing === []) {
            return null;
        }

        return count($requiredKeys) === 1 ? $missing[0] : 'required fields';
    }

    /**
     * Whether a decoded value matches the shape its schema override declares.
     * Every required key defaults to a plain string; a key overridden to
     * `type: array` (e.g. a claims list) is checked as an array instead.
     *
     * @param  array<string, mixed>|null  $schema
     */
    private static function matchesPropertyType(mixed $value, ?array $schema): bool
    {
        return ($schema['type'] ?? 'string') === 'array' ? is_array($value) : is_string($value);
    }

    /**
     * The Responses API structured-output format (text.format): json_schema with
     * the fields flattened, unlike chat completions' nested `json_schema` wrapper.
     *
     * @param  list<string>  $requiredKeys
     * @param  array<string, array<string, mixed>>|null  $propertySchema
     * @return array{type: string, name: string, strict: bool, schema: array<string, mixed>}
     */
    private static function textFormat(string $schemaName, array $requiredKeys, ?array $propertySchema): array
    {
        $properties = [];
        foreach ($requiredKeys as $key) {
            $properties[$key] = $propertySchema[$key] ?? ['type' => 'string'];
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
