<?php

declare(strict_types=1);

namespace App\Services\Inertia;

use App\Models\RaceGoal;
use App\Models\RunCard;
use App\Models\User;
use App\Services\Gamification\EquippedAccessories;
use App\Services\Gamification\GoalResolver;
use App\Services\Run\Story\CardPresenter;
use App\Support\SharedPropCacheKey;
use Closure;

/**
 * The collection-and-progress family of shared props: what the mascot is
 * wearing, the card waiting to be revealed, and how the user's goals are going.
 *
 * Every prop is returned as a closure, so Inertia skips the work entirely on a
 * partial reload that did not ask for that key.
 */
final readonly class GamificationProps
{
    public function __construct(
        private EquippedAccessories $equippedAccessories,
        private GoalResolver $goals,
        private CardPresenter $cards,
    ) {
    }

    /**
     * @return array<string, Closure>
     */
    public function forUser(?User $user): array
    {
        return [
            'equippedAccessories' => fn (): array => $this->equippedAccessoriesFor($user),
            'pendingReveal' => fn () => $this->pendingRevealFor($user),
            'goalsSummary' => fn () => $this->goalsSummaryFor($user),
            'activeRace' => fn () => $this->activeRaceFor($user),
        ];
    }

    /**
     * The race the user is currently training for, shared app-wide. Kept
     * deliberately thin (no Riegel projection) — the projection is only
     * computed on the Race page itself, not on every page load.
     *
     * @return array{id: int, race_date: string, distance_m: int, goal_time_sec: int, name: string|null}|null
     */
    private function activeRaceFor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return SharedPropCacheKey::ActiveRace->remember(
            $user->id,
            function () use ($user): ?array {
                $race = RaceGoal::query()->where('user_id', $user->id)->active()->first();

                return $race === null ? null : [
                    'id' => $race->id,
                    'race_date' => $race->race_date->toDateString(),
                    'distance_m' => $race->distance_m,
                    'goal_time_sec' => $race->goal_time_sec,
                    'name' => $race->name,
                ];
            },
        );
    }

    /**
     * Which accessories the mascot is wearing. Cached because it costs a
     * `user_unlocks` scan on every page load while only ever moving when the
     * user equips something ({@see \App\Http\Controllers\AksesoriController}
     * busts it there). Granting an unlock cannot change it: rows are inserted
     * without `equipped`, which defaults to false.
     *
     * @return array<string, string|null>
     */
    private function equippedAccessoriesFor(?User $user): array
    {
        if ($user === null) {
            return $this->equippedAccessories->forUser(null);
        }

        return SharedPropCacheKey::EquippedAccessories->remember(
            $user->id,
            fn (): array => $this->equippedAccessories->forUser($user),
        );
    }

    /**
     * @return array{total: int, completed: int, closest: list<array{id: string, title: string, current: int|float, target: int|float, unit: string}>}|null
     */
    private function goalsSummaryFor(?User $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return SharedPropCacheKey::GoalsSummary->remember(
            $user->id,
            fn (): array => $this->computeGoalsSummary($user),
        );
    }

    /**
     * @return array{total: int, completed: int, closest: list<array{id: string, title: string, current: int|float, target: int|float, unit: string}>}
     */
    private function computeGoalsSummary(User $user): array
    {
        $goals = $this->goals->forUser($user);
        $completed = $this->goals->completedCount($goals);
        $closest = $this->goals->closestToCompletion($user, 3, $goals);

        return [
            'total' => count($goals),
            'completed' => $completed,
            'closest' => array_map(fn (array $g): array => [
                'id' => $g['id'],
                'title' => $g['title'],
                'current' => $g['current'],
                'target' => $g['target'],
                'unit' => $g['unit'],
            ], $closest),
        ];
    }

    /**
     * @return array{card_id: int, activity_id: int, rarity: string, special_move: string, mood: string, badges: array<int, string>|null, detail_name: string|null, distance_m: float|null, elapsed_time_sec: int|null, trimp_edwards: float|null, average_heartrate: float|null, stream_summary: array<string, mixed>|null, summary_polyline: string|null, public_share_url: string, edition: array{index: int, total: int}}|null
     */
    private function pendingRevealFor(?User $user): ?array
    {
        if ($user === null || $user->pending_reveal_card_id === null) {
            return null;
        }

        $card = RunCard::query()
            ->whereKey($user->pending_reveal_card_id)
            ->with([
                'activity.detail:id,activity_id,name,distance,elapsed_time,trimp_edwards,average_heartrate,summary_polyline,stream_summary,weather_temp_c',
                'activity.postRunStoryLine',
                'activity:id,user_id',
            ])
            ->first();

        if ($card === null || $card->activity->user_id !== $user->id) {
            return null;
        }

        /** @var array<int, string>|null $badges */
        $badges = $card->badges;

        $detail = $card->activity->detail;

        return [
            'card_id' => $card->id,
            'activity_id' => $card->activity_id,
            'rarity' => $card->rarity->value,
            'special_move' => $card->special_move,
            'mood' => $this->cards->mood($card),
            'badges' => $badges,
            'detail_name' => $detail?->name,
            'distance_m' => $detail?->distance,
            'elapsed_time_sec' => $detail?->elapsed_time,
            'trimp_edwards' => $detail?->trimp_edwards,
            'average_heartrate' => $detail?->average_heartrate,
            'stream_summary' => $detail?->stream_summary,
            'summary_polyline' => $detail?->summary_polyline,
            'public_share_url' => route('activities.show', ['activity' => $card->activity_id]),
            'edition' => $this->cards->edition($card, $user->id),
        ];
    }
}
