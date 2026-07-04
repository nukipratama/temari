<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\Temari;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
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
            ->forUser($user->id)
            ->with(['activity.detail', 'activity.postRunStoryLine'])
            ->when($rarity, fn ($q) => $q->where('rarity', $rarity))
            // Newest-first: the collection reads as a chronological feed (a filter
            // tab narrows to one rarity; the rarity-rank pick lives on the banner).
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        $counts = $this->rarityCounts($user);
        $editions = $this->editionIndexMap($user);

        $page->getCollection()->each(function (RunCard $c) use ($editions, $counts): void {
            $c->setAttribute('edition', $this->edition($c, $editions, $counts));
            $c->setAttribute('mood', $c->activity->postRunStoryLine->mood ?? Temari::moodForActivityOrDefault($c->activity));
        });

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

        $card->loadMissing('activity.detail', 'activity.postRunStoryLine');

        $counts = $this->rarityCounts($user);
        $editions = $this->editionIndexMap($user);

        $flavorAnalysis = Analysis::query()
            ->forSubject(RunCard::class, $card->id, AnalysisType::CardFlavor)
            ->first();

        $relatedCards = RunCard::query()
            ->forUser($user->id)
            ->with(['activity.detail', 'activity.postRunStoryLine'])
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
                // Signed public URL for the share modal — minted server-side since
                // signing needs the app key. Recipients open it without a session.
                'public_share_url' => URL::signedRoute('kartu.publik', ['card' => $card->id]),
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
     * @return array{id: int, activity_id: int, rarity: string, special_move: string, mood: string, badges: array<int, string>|null, detail: ActivityDetail|null, edition: array{index: int, total: int}}
     */
    private function cardPayload(RunCard $card, array $editions, array $counts): array
    {
        return [
            'id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'mood' => $card->activity->postRunStoryLine->mood ?? Temari::moodForActivityOrDefault($card->activity),
            'badges' => $card->badges,
            'detail' => $card->activity->detail,
            'edition' => $this->edition($card, $editions, $counts),
        ];
    }

    /**
     * @param  array<int, int>  $editions
     * @param  array<string, int>  $counts
     * @return array{id: int, activity_id: int, rarity: string, special_move: string, mood: string, badges: array<int, string>|null, detail: ActivityDetail|null, edition: array{index: int, total: int}, flavor_analysis: array<string, mixed>}|null
     */
    private function featuredCard(User $user, ?string $rarity, array $editions, array $counts): ?array
    {
        $query = RunCard::query()
            ->forUser($user->id)
            ->with(['activity.detail', 'activity.postRunStoryLine']);

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
            ->forUser($user->id)
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
            ->forUser($user->id)
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
