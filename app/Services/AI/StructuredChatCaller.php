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
use OpenAI\Responses\Chat\CreateResponse;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Thin shared wrapper around the Azure OpenAI chat completions call. Handles
 * the request, structured-JSON decoding, logging, and exception mapping that
 * every narrator otherwise duplicates.
 */
final readonly class StructuredChatCaller
{
    /**
     * Hard ceiling for the truncation retry's bumped token cap, so a runaway
     * schema can't balloon a single block's cost.
     */
    private const int MAX_RETRY_COMPLETION_TOKENS = 4000;

    public function __construct(
        private AzureOpenAIClient $azure,
        private TokenUsageRecorder $usageRecorder,
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
            'messages' => [
                ['role' => 'system', 'content' => TemariPersona::systemPrompt()."\n\n".$systemPrompt],
                ['role' => 'user', 'content' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ],
            'max_completion_tokens' => $effectiveMaxTokens,
            'temperature' => $options->temperature,
            'response_format' => self::responseFormat($schemaName, $requiredKeys),
        ];

        $response = $this->createChat($kind, $payload, $startedAt);

        // Truncated structured output is unparseable, so retry once at a higher
        // token cap, bounded by self::MAX_RETRY_COMPLETION_TOKENS.
        if (self::isTruncated($response)) {
            $retryMaxTokens = min((int) ceil($effectiveMaxTokens * 1.5), self::MAX_RETRY_COMPLETION_TOKENS);
            if ($retryMaxTokens > $effectiveMaxTokens) {
                Log::warning('narrator.ai.truncated_retry', [
                    'kind' => $kind,
                    'max_completion_tokens' => $effectiveMaxTokens,
                    'retry_max_completion_tokens' => $retryMaxTokens,
                ]);
                $effectiveMaxTokens = $retryMaxTokens;
                $payload['max_completion_tokens'] = $effectiveMaxTokens;
                $response = $this->createChat($kind, $payload, $startedAt);
            }
        }

        $content = (string) ($response->choices[0]->message->content ?? '');

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

        $promptTokens = (int) ($response->usage->promptTokens ?? 0);
        $completionTokens = (int) ($response->usage->completionTokens ?? 0);
        $totalTokens = (int) ($response->usage->totalTokens ?? 0);
        $truncated = self::isTruncated($response);
        $latencyMs = self::latencyMs($startedAt);

        if ($truncated) {
            Log::warning('narrator.ai.truncated', [
                'kind' => $kind,
                'completion_tokens' => $completionTokens,
                'max_completion_tokens' => $effectiveMaxTokens,
            ]);
        }

        Log::info('narrator.ai.call', [
            'kind' => $kind,
            'status' => 'ok',
            'latency_ms' => $latencyMs,
            'truncated' => $truncated,
            'usage' => [
                'prompt' => $promptTokens,
                'completion' => $completionTokens,
                'total' => $totalTokens,
            ],
        ]);

        $this->usageRecorder->record(
            $kind,
            $promptTokens,
            $completionTokens,
            $totalTokens,
            $deployment !== '' ? $deployment : null,
            $latencyMs,
            $truncated,
            $options->userId,
        );

        return $decoded;
    }

    /**
     * Issue one chat-completions request, mapping any Azure failure into the
     * caller's transient/terminal exception taxonomy.
     *
     * @param  array<string, mixed>  $payload
     */
    private function createChat(string $kind, array $payload, float $startedAt): CreateResponse
    {
        try {
            return $this->azure->client()->chat()->create($payload);
        } catch (Throwable $e) {
            Log::warning('narrator.ai.call', [
                'kind' => $kind,
                'status' => 'fail',
                'error' => $e->getMessage(),
                'latency_ms' => self::latencyMs($startedAt),
            ]);
            throw self::mapAzureThrowable($e);
        }
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
        return (string) ($response->choices[0]->finishReason ?? '') === 'length';
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
     * @param  list<string>  $requiredKeys
     * @return array{type: string, json_schema: array<string, mixed>}
     */
    private static function responseFormat(string $schemaName, array $requiredKeys): array
    {
        $properties = [];
        foreach ($requiredKeys as $key) {
            $properties[$key] = ['type' => 'string'];
        }

        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaName,
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => $properties,
                    'required' => $requiredKeys,
                ],
            ],
        ];
    }

    private static function latencyMs(float $startedAt): int
    {
        return (int) ((microtime(true) - $startedAt) * 1000);
    }
}
