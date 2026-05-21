<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\PersonalRecord;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;

class PrContextNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: 1 kalimat flavor untuk Personal Record, maksimal 22 kata.

        Highlight delta dari PR sebelumnya jika ada. Kalau ini PR pertama di
        kategori, sebutkan "PR pertama!" atau yang setara. Tone selalu bangga dan
        suportif.
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
            options: new ChatCallOptions(temperature: 0.7, userId: $pr->user_id, maxTokens: 500),
        );

        return (string) $decoded['flavor'];
    }
}
