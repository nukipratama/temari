<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisType;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CardController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $rarity = $request->query('rarity');
        $rarity = \is_string($rarity) && $rarity !== '' ? $rarity : null;

        $page = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->with(['activity.detail'])
            ->when($rarity, fn ($q) => $q->where('rarity', $rarity))
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        $counts = $this->rarityCounts($user);
        $editions = $this->editionIndexMap($user);

        $page->getCollection()->each(
            fn (RunCard $c) => $c->setAttribute('edition', $this->edition($c, $editions, $counts)),
        );

        return Inertia::render('Koleksi/Kartu', [
            'cards' => $page,
            'selectedRarity' => $rarity,
            'featuredCard' => $this->featuredCard($user, $rarity, $editions, $counts),
            'rarityCounts' => $counts,
        ]);
    }

    public function show(Request $request, RunCard $card): Response
    {
        /** @var User $user */
        $user = $request->user();

        $card->loadMissing('activity');
        abort_if($card->activity->user_id !== $user->id, 404);

        $card->loadMissing('activity.detail');

        $counts = $this->rarityCounts($user);
        $editions = $this->editionIndexMap($user);

        $flavorAnalysis = Analysis::query()
            ->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)
            ->first();

        $relatedCards = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->with(['activity.detail'])
            ->where('rarity', $card->rarity)
            ->where('id', '!=', $card->id)
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (RunCard $c) => $this->cardPayload($c, $editions, $counts))
            ->values();

        return Inertia::render('Koleksi/KartuDetail', [
            'card' => [
                ...$this->cardPayload($card, $editions, $counts),
                'flavor_analysis' => Analysis::toPayload($flavorAnalysis, AnalysisType::CardFlavor, RunCard::class, $card->id),
            ],
            'relatedCards' => $relatedCards,
            'totalForRarity' => $counts[$card->rarity->value] ?? 0,
        ]);
    }

    /**
     * The shared card shape every endpoint returns. Keep the three call sites
     * (related, detail, featured) on this single mapper so they can't drift.
     *
     * @param  array<int, int>  $editions
     * @param  array<string, int>  $counts
     * @return array{id: int, activity_id: int, rarity: string, special_move: string, badges: array<int, string>|null, detail: ActivityDetail|null, edition: array{index: int, total: int}}
     */
    private function cardPayload(RunCard $card, array $editions, array $counts): array
    {
        return [
            'id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'badges' => $card->badges,
            'detail' => $card->activity->detail,
            'edition' => $this->edition($card, $editions, $counts),
        ];
    }

    /**
     * @param  array<int, int>  $editions
     * @param  array<string, int>  $counts
     * @return array{id: int, activity_id: int, rarity: string, special_move: string, badges: array<int, string>|null, detail: ActivityDetail|null, edition: array{index: int, total: int}, flavor_analysis: array<string, mixed>}|null
     */
    private function featuredCard(User $user, ?string $rarity, array $editions, array $counts): ?array
    {
        $query = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->with(['activity.detail']);

        if ($rarity !== null) {
            $query->where('rarity', $rarity);
        } else {
            $query
                ->orderByRaw("FIELD(rarity, 'legendary', 'epic', 'rare', 'uncommon', 'common')");
        }

        $card = $query->orderByDesc('id')->first();
        if ($card === null) {
            return null;
        }

        $flavor = Analysis::query()
            ->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)
            ->first();

        return [
            ...$this->cardPayload($card, $editions, $counts),
            'flavor_analysis' => Analysis::toPayload($flavor, AnalysisType::CardFlavor, RunCard::class, $card->id),
        ];
    }

    /**
     * Map of card id => 1-based edition index within its rarity (chronological by id),
     * for the user's whole collection. One window-function pass, no N+1.
     *
     * @return array<int, int>
     */
    private function editionIndexMap(User $user): array
    {
        return RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
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
    private function edition(RunCard $card, array $editions, array $counts): array
    {
        return [
            'index' => $editions[$card->id] ?? 1,
            'total' => $counts[$card->rarity->value] ?? 1,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function rarityCounts(User $user): array
    {
        $rows = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
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
}
