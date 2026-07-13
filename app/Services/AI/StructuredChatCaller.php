<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Exceptions\AI\TransientUpstreamException;
use App\Exceptions\AI\UnavailableException;
use Illuminate\Support\Facades\Log;
use JsonException;
use OpenAI\Exceptions\ErrorException;
use OpenAI\Exceptions\RateLimitException;
use OpenAI\Exceptions\ServerException;
use OpenAI\Exceptions\TransporterException;
use OpenAI\Responses\Responses\CreateResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Thin shared wrapper around the Azure OpenAI Responses API call. Handles the
 * request, structured-JSON decoding, logging, and exception mapping that every
 * narrator otherwise duplicates. The Responses surface (vs chat completions) is
 * what serves both chat and reasoning/codex deployments.
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

        $payload = [
            'model' => $deployment,
            'input' => [
                ['role' => 'system', 'content' => TemariPersona::systemPrompt()."\n\n".$systemPrompt],
                ['role' => 'user', 'content' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ],
            'max_output_tokens' => $effectiveMaxTokens,
            'temperature' => $options->temperature,
            'text' => ['format' => self::textFormat($schemaName, $requiredKeys)],
        ];

        $response = $this->createResponse($kind, $payload, $startedAt);

        // Truncated structured output is unparseable, so retry once at a higher
        // token cap, bounded by self::MAX_RETRY_OUTPUT_TOKENS.
        if (self::isTruncated($response)) {
            $retryMaxTokens = min((int) ceil($effectiveMaxTokens * 1.5), self::MAX_RETRY_OUTPUT_TOKENS);
            if ($retryMaxTokens > $effectiveMaxTokens) {
                Log::warning('narrator.ai.truncated_retry', [
                    'kind' => $kind,
                    'max_output_tokens' => $effectiveMaxTokens,
                    'retry_max_output_tokens' => $retryMaxTokens,
                ]);
                $effectiveMaxTokens = $retryMaxTokens;
                $payload['max_output_tokens'] = $effectiveMaxTokens;
                $response = $this->createResponse($kind, $payload, $startedAt);
            }
        }

        $content = (string) ($response->outputText ?? '');

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
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

        $inputTokens = (int) ($response->usage->inputTokens ?? 0);
        $outputTokens = (int) ($response->usage->outputTokens ?? 0);
        $totalTokens = (int) ($response->usage->totalTokens ?? 0);
        $truncated = self::isTruncated($response);
        $latencyMs = self::latencyMs($startedAt);

        if ($truncated) {
            Log::warning('narrator.ai.truncated', [
                'kind' => $kind,
                'output_tokens' => $outputTokens,
                'max_output_tokens' => $effectiveMaxTokens,
            ]);
        }

        Log::info('narrator.ai.call', [
            'kind' => $kind,
            'status' => 'ok',
            'latency_ms' => $latencyMs,
            'truncated' => $truncated,
            'usage' => [
                'input' => $inputTokens,
                'output' => $outputTokens,
                'total' => $totalTokens,
            ],
        ]);

        // The usage table's prompt/completion columns hold input/output tokens.
        $this->usageRecorder->record(
            $kind,
            $inputTokens,
            $outputTokens,
            $totalTokens,
            $deployment !== '' ? $deployment : null,
            $latencyMs,
            $truncated,
            $options->userId,
        );

        return $decoded;
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
