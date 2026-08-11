<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use App\Services\Run\Story\CardPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CardController extends Controller
{
    public function index(Request $request, CardPresenter $cards): Response
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

        $counts = $cards->rarityCounts($user->id);
        $editions = $cards->editionIndexMap($user->id);

        $page->getCollection()->each(function (RunCard $c) use ($cards, $editions, $counts): void {
            $c->setAttribute('edition', $cards->editionFromMap($c, $editions, $counts));
            $c->setAttribute('mood', $cards->mood($c));
        });

        return Inertia::render('Collection/Cards', [
            'cards' => $page,
            'selectedRarity' => $rarity,
            'featuredCard' => $this->featuredCard($cards, $user, $rarity, $editions, $counts),
            'rarityCounts' => $counts,
        ]);
    }

    /**
     * @param  array<int, int>  $editions
     * @param  array<string, int>  $counts
     * @return array{id: int, activity_id: int, rarity: string, special_move: string, mood: string, badges: array<int, string>|null, detail: ActivityDetail|null, edition: array{index: int, total: int}, flavor_analysis: array<string, mixed>}|null
     */
    private function featuredCard(CardPresenter $cards, User $user, ?string $rarity, array $editions, array $counts): ?array
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

        return [
            ...$cards->base($card),
            'mood' => $cards->mood($card),
            'detail' => $card->activity->detail,
            'edition' => $cards->editionFromMap($card, $editions, $counts),
            'flavor_analysis' => $cards->flavorAnalysis($card),
        ];
    }
}
