<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Services\AI\AnalysisType;

class CardPresenter
{
    /**
     * @return array{index: int, total: int}
     */
    public function edition(RunCard $card, int $userId): array
    {
        // One aggregate pass for both the edition index and the rarity total,
        // instead of two separate COUNT queries.
        $stats = RunCard::query()
            ->forUser($userId)
            ->where('rarity', $card->rarity)
            ->selectRaw('COUNT(*) as total, SUM(id <= ?) as edition_index', [$card->id])
            ->first();

        return [
            'index' => (int) $stats?->getAttribute('edition_index'),
            'total' => (int) $stats?->getAttribute('total'),
        ];
    }

    /**
     * @return array{id: int, activity_id: int, rarity: string, special_move: string, badges: array<int, string>|null}
     */
    public function base(RunCard $card): array
    {
        // Explicit whitelist (not `...$card->toArray()`) so no internal column
        // ever leaks into the Inertia payload.
        return [
            'id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'badges' => $card->badges,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function flavorAnalysis(RunCard $card): array
    {
        $flavor = Analysis::query()
            ->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)
            ->first();

        return Analysis::toPayload($flavor, AnalysisType::CardFlavor, RunCard::class, $card->id);
    }
}
