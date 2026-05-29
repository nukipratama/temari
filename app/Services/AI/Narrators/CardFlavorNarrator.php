<?php

declare(strict_types=1);

namespace App\Services\AI\Narrators;

use App\Models\RunCard;
use App\Services\AI\ChatCallOptions;
use App\Services\AI\StructuredChatCaller;
use App\Services\Run\Metrics\PaceCalculator;

class CardFlavorNarrator
{
    private const string SYSTEM_PROMPT = <<<'PROMPT'
        Tugas: berikan 1 kalimat flavor maksimal 22 kata untuk kartu aktivitas.
        Setiap kartu punya rarity (common, uncommon, rare, epic, legendary) +
        special move + badges. Saat menyebut rarity dalam kalimat, gunakan
        label Bahasa Indonesia: Biasa / Berkesan / Langka / Luar Biasa / Legendaris.

        Rajut kombinasi badge, pacing, dan cuaca jadi 1 kalimat yang
        nunjukin kenapa kartu ini spesial.
        PROMPT;

    public function __construct(private readonly StructuredChatCaller $caller)
    {
    }

    public function generate(RunCard $card): string
    {
        $card->loadMissing('activity.detail');
        $detail = $card->activity->detail;
        $distance = $detail?->distance;
        $movingTime = $detail?->moving_time;
        $paceSecPerKm = PaceCalculator::secPerKm($distance !== null ? (float) $distance : null, $movingTime);

        $context = [
            'rarity' => $card->rarity->value,
            'rarity_label' => $card->rarity->label(),
            'special_move' => $card->special_move,
            'badges' => $card->badges,
            'distance_km' => $distance !== null ? round((float) $distance / 1000, 2) : null,
            'pace_sec_per_km' => $paceSecPerKm !== null ? round($paceSecPerKm, 1) : null,
            'weather_temp_c' => $detail?->weather_temp_c,
            'weather_rain' => $detail?->weather_rain_detected,
        ];

        $decoded = $this->caller->call(
            kind: 'card_flavor',
            systemPrompt: self::SYSTEM_PROMPT,
            context: $context,
            schemaName: 'TemariCardFlavor',
            requiredKeys: ['flavor'],
            options: new ChatCallOptions(userId: $card->activity->user_id, maxTokens: 400),
        );

        return (string) $decoded['flavor'];
    }
}
