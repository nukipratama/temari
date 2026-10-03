<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Builds the season read models shared by the Plan tab and the
 * Profile page. Purely a read: {@see \App\Services\Run\Plan\PlanPageAssembler}
 * passes it the {@see Season} its own {@see
 * \App\Services\Run\Plan\SeasonService::ensureCurrent()} call already
 * created, while {@see \App\Http\Controllers\ProfileController} passes
 * whatever {@see \App\Services\Run\Plan\SeasonService::peekCurrent()} finds
 * (possibly `null`) — a second page load must never trigger season creation
 * on its own.
 */
final readonly class SeasonPayloadBuilder
{
    public function __construct(
        private SeasonGoalResolver $seasonGoalResolver,
    ) {
    }

    /**
     * @return array{starts_at: string, ends_at: string, week_index: int, total_weeks: int}|null
     */
    public function seasonPayload(?Season $season, Carbon $today): ?array
    {
        if ($season === null) {
            return null;
        }

        $firstMonday = $season->starts_at->copy()->startOfWeek(Carbon::MONDAY);
        $totalWeeks = max(1, (int) $firstMonday->diffInWeeks($season->ends_at->copy()->startOfWeek(Carbon::MONDAY)) + 1);
        $weekIndex = max(1, min($totalWeeks, (int) $firstMonday->diffInWeeks($today->copy()->startOfWeek(Carbon::MONDAY)) + 1));

        return [
            'starts_at' => $season->starts_at->toDateString(),
            'ends_at' => $season->ends_at->toDateString(),
            'week_index' => $weekIndex,
            'total_weeks' => $totalWeeks,
        ];
    }

    /**
     * @return array{starts_at: string, ends_at: string, goals: list<array{id: int, title: string, current: int|float, target: int|float, unit: string, is_completed: bool}>}|null
     */
    public function profileSeasonPayload(User $user, ?Season $season, Carbon $today): ?array
    {
        if ($season === null) {
            return null;
        }

        return [
            'starts_at' => $season->starts_at->toDateString(),
            'ends_at' => $season->ends_at->toDateString(),
            'goals' => $this->seasonGoalResolver->forSeason($user, $season, today: $today),
        ];
    }
}
