<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Exceptions\AI\UnavailableException;
use App\Models\User;
use App\Services\AI\AzureOpenAIClient;
use App\Services\Run\Story\Vibe;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

use function is_array;

class DailyGreetingNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 1 kalimat greeting pagi
buat user, max 20 kata. Bahasa Indonesia santai (gen-z friendly),
istilah lari tetep bahasa Inggris.

Tone disesuain vibe state: pumped/fresh/bouncy=hype + ngajakin,
worn_down/cooked=lembut + permisif (rest gak apa-apa), stretched_thin=
warning halus, hibernating=ngajakin keluar lagi.

JANGAN preachy, JANGAN data dump. Cuma 1 kalimat hangat yang nyapa.
PROMPT;

    public function __construct(
        private readonly AzureOpenAIClient $azure,
    ) {
    }

    public function generate(User $user, string $vibeState): string
    {
        return $this->call([
            'name' => $user->firstName(),
            'vibe' => $vibeState,
            'vibe_label' => Vibe::label($vibeState),
        ]);
    }

    /** @param  array<string, mixed>  $ctx */
    private function call(array $ctx): string
    {
        $startedAt = microtime(true);

        try {
            $response = $this->azure->client()->chat()->create([
                'model' => (string) config('azure_openai.deployment'),
                'messages' => [
                    ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                    ['role' => 'user', 'content' => json_encode($ctx, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
                ],
                'max_tokens' => (int) config('azure_openai.max_tokens'),
                'temperature' => 0.8,
                'response_format' => $this->responseFormat(),
            ]);
        } catch (Throwable $e) {
            Log::warning('narrator.ai.call', [
                'kind' => 'daily_greeting',
                'status' => 'fail',
                'error' => $e->getMessage(),
                'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            ]);
            throw new UnavailableException('Azure OpenAI call failed: '.$e->getMessage(), previous: $e);
        }

        $content = (string) ($response->choices[0]->message->content ?? '');

        try {
            $decoded = json_decode($content, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new UnavailableException('Azure OpenAI returned non-JSON: '.$e->getMessage());
        }

        if (! is_array($decoded) || ! isset($decoded['speech']) || ! is_string($decoded['speech'])) {
            throw new UnavailableException('Azure OpenAI structured output missing speech');
        }

        Log::info('narrator.ai.call', [
            'kind' => 'daily_greeting',
            'status' => 'ok',
            'latency_ms' => (int) ((microtime(true) - $startedAt) * 1000),
            'usage' => [
                'prompt' => $response->usage->promptTokens ?? null,
                'completion' => $response->usage->completionTokens ?? null,
                'total' => $response->usage->totalTokens ?? null,
            ],
        ]);

        return $decoded['speech'];
    }

    /** @return array{type: string, json_schema: array<string, mixed>} */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'TemariDailyGreeting',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'speech' => ['type' => 'string'],
                    ],
                    'required' => ['speech'],
                ],
            ],
        ];
    }
}
