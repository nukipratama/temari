<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\RunCard;
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

        $query = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->with(['activity.detail'])
            ->orderByDesc('id');

        if (is_string($rarity) && $rarity !== '') {
            $query->where('rarity', $rarity);
        }

        return Inertia::render('Cards/Index', [
            'cards' => $query->paginate(24)->withQueryString(),
            'selectedRarity' => is_string($rarity) ? $rarity : null,
        ]);
    }
}
