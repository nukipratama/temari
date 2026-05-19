<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\User;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Story\Vibe;

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

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(User $user, string $vibeState): string
    {
        $decoded = $this->caller->call(
            kind: 'daily_greeting',
            systemPrompt: self::SYSTEM_PROMPT,
            context: [
                'name' => $user->firstName(),
                'vibe' => $vibeState,
                'vibe_label' => Vibe::label($vibeState),
            ],
            schemaName: 'TemariDailyGreeting',
            requiredKeys: ['speech'],
        );

        return (string) $decoded['speech'];
    }
}
