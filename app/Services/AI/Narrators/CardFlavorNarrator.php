<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Exceptions\AI\UnavailableException;
use App\Models\RunCard;
use App\Services\AI\AzureOpenAIClient;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;

use function is_array;

class CardFlavorNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Tiap kartu aktivitas punya rarity (biasa,
jarang, langka, epik, legendaris) + special move + badges. Buat 1 kalimat
flavor max 22 kata yang ngejelasin kenapa kartu ini istimewa.

Pakai bahasa Indonesia santai (gen-z friendly), istilah lari bahasa Inggris.
Sebut kombinasi badge / pacing / cuaca jadi 1 kalimat naratif yang menarik.

JANGAN preachy, JANGAN data dump.
PROMPT;

    public function __construct(private readonly AzureOpenAIClient $azure)
    {
    }

    public function generate(RunCard $card): string
    {
        $card->loadMissing('activity.detail');
        $detail = $card->activity->detail;

        return $this->call([
            'rarity' => $card->rarity,
            'rarity_label' => RunCard::RARITY_LABELS[$card->rarity] ?? $card->rarity,
            'special_move' => $card->special_move,
            'badges' => $card->badges,
            'distance_km' => $detail !== null && $detail->distance !== null
                ? round((float) $detail->distance / 1000, 2)
                : null,
            'pace_sec_per_km' => $detail !== null && $detail->distance !== null && $detail->distance > 0 && $detail->moving_time !== null
                ? round($detail->moving_time / ($detail->distance / 1000), 1)
                : null,
            'weather_temp_c' => $detail?->weather_temp_c,
            'weather_rain' => $detail?->weather_rain_detected,
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
                'kind' => 'card_flavor',
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

        if (! is_array($decoded) || ! isset($decoded['flavor']) || ! is_string($decoded['flavor'])) {
            throw new UnavailableException('Azure OpenAI structured output missing flavor');
        }

        return $decoded['flavor'];
    }

    /** @return array{type: string, json_schema: array<string, mixed>} */
    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'TemariCardFlavor',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'flavor' => ['type' => 'string'],
                    ],
                    'required' => ['flavor'],
                ],
            ],
        ];
    }
}
