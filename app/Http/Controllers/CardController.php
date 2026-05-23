<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
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

        return Inertia::render('Koleksi/Kartu', [
            'cards' => $page,
            'selectedRarity' => $rarity,
            'featuredCard' => $this->featuredCard($user, $rarity),
            'rarityCounts' => $this->rarityCounts($user),
        ]);
    }

    /**
     * @return array{id: int, activity_id: int, rarity: string, special_move: string, badges: array<int, string>|null, detail: ActivityDetail|null}|null
     */
    private function featuredCard(User $user, ?string $rarity): ?array
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

        $card->loadMissing('activity.detail');

        return [
            'id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity,
            'special_move' => $card->special_move,
            'badges' => $card->badges,
            'detail' => $card->activity->detail,
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
