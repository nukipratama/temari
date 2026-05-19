<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PersonalRecord;
use App\Services\AI\StructuredChatCaller;

class PrContextNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
Lo Temari, temen lari di TemanLari. Buat 1 kalimat flavor untuk Personal Record
user (max 22 kata), bahasa Indonesia santai (gen-z friendly), istilah lari bahasa
Inggris.

Highlight kalau ada delta dari PR sebelumnya. Kalau ini PR pertama di kategori,
bilang "PR pertama!" atau yang setara. Tone selalu bangga + supportive.

JANGAN preachy, JANGAN data dump.
PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(PersonalRecord $pr): string
    {
        $previous = PersonalRecord::query()
            ->where('user_id', $pr->user_id)
            ->where('category', $pr->category)
            ->where('id', '<>', $pr->id)
            ->orderByDesc('set_at')
            ->first();

        $decoded = $this->caller->call(
            kind: 'pr_context',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'category' => $pr->category,
                'value_sec' => $pr->value_sec,
                'set_at' => $pr->set_at->toDateString(),
                'previous_value_sec' => $previous?->value_sec,
                'previous_set_at' => $previous?->set_at?->toDateString(),
                'delta_sec' => $previous !== null ? ($previous->value_sec - $pr->value_sec) : null,
            ],
            schemaName: 'TemariPrContext',
            requiredKeys: ['flavor'],
            temperature: 0.7,
        );

        return (string) $decoded['flavor'];
    }
}
