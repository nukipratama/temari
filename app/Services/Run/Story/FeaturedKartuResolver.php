<?php

declare(strict_types=1);

namespace App\Services\Run\Story;

use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Single source of truth for the featured "Kartu andalan dari Temari": the
 * highest-rarity card among the user's last few runs (ties broken toward the
 * most recent), not scoped to a calendar week. Both the dashboard hero and the
 * narration key off this one pick, so the displayed card and the "Kata Temari"
 * quote can never describe different cards.
 */
class FeaturedKartuResolver
{
    private const int WINDOW = 8;

    public function resolve(User $user): ?RunCard
    {
        $details = ActivityDetail::query()
            ->whereHas('activity', fn (Builder $q): Builder => $q->where('user_id', $user->id))
            ->with('activity.runCard')
            ->orderByDesc('start_date_local')
            ->limit(self::WINDOW)
            ->get(['id', 'activity_id', 'start_date_local']);

        $best = null;
        $bestRank = -1;

        // Details are newest-first, so a strict `>` keeps the most recent card on
        // a rarity tie (mirrors the client-side `featuredCardFor`).
        foreach ($details as $detail) {
            $card = $detail->activity->runCard;
            if ($card === null) {
                continue;
            }
            $rank = $card->rarity->rank();
            if ($rank > $bestRank) {
                $bestRank = $rank;
                $best = $card;
            }
        }

        return $best;
    }
}
