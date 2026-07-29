<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Services\AI\AnalysisType;

class CardPresenter
{
    /**
     * @return array<string, int>
     */
    public function rarityCounts(int $userId): array
    {
        $rows = RunCard::query()
            ->forUser($userId)
            ->selectRaw('rarity, COUNT(*) as total')
            ->groupBy('rarity')
            ->pluck('total', 'rarity')
            ->all();

        return [
            'common' => (int) ($rows['common'] ?? 0),
            'uncommon' => (int) ($rows['uncommon'] ?? 0),
            'rare' => (int) ($rows['rare'] ?? 0),
            'epic' => (int) ($rows['epic'] ?? 0),
            'legendary' => (int) ($rows['legendary'] ?? 0),
        ];
    }

    /**
     * Map of card id => 1-based edition index within its rarity (chronological by id),
     * for the user's whole collection. One window-function pass, no N+1.
     *
     * @return array<int, int>
     */
    public function editionIndexMap(int $userId): array
    {
        return RunCard::query()
            ->forUser($userId)
            ->selectRaw('id, ROW_NUMBER() OVER (PARTITION BY rarity ORDER BY id) AS edition_index')
            ->pluck('edition_index', 'id')
            ->map(fn ($index): int => (int) $index)
            ->all();
    }

    /**
     * @param  array<int, int>  $editions
     * @param  array<string, int>  $counts
     * @return array{index: int, total: int}
     */
    public function editionFromMap(RunCard $card, array $editions, array $counts): array
    {
        return [
            'index' => $editions[$card->id] ?? 1,
            'total' => $counts[$card->rarity->value] ?? 1,
        ];
    }

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
        // Explicit whitelist (not `...$card->toArray()`) so internal columns
        // like `share_image_path` never leak into the Inertia payload.
        return [
            'id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'badges' => $card->badges,
        ];
    }

    public function mood(RunCard $card): string
    {
        return $card->activity->postRunStoryLine->mood ?? Temari::moodForActivityOrDefault($card->activity);
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
