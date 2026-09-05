<?php

declare(strict_types=1);

namespace App\Actions\Gamification;

use App\Models\Season;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Gamification\SeasonGamificationContext;
use App\Services\Gamification\SeasonGoalResolver;
use App\Services\Run\Plan\SeasonService;
use Illuminate\Support\Carbon;

/**
 * The rest-day reward: not a {@see \App\Enums\Badge} case, because every
 * `Badge` grant requires a real ingested `Activity` (`run_cards.activity_id`
 * is a required unique FK) and a rest day, by definition, has none. Reuses
 * {@see UserUnlock}'s shape instead, in a namespace outside the 25 lifetime
 * accessory keys, scoped PER SEASON via the season id in the unlock_key
 * itself (`season.{id}.rest_honored_{N}`) — that's what makes the same
 * threshold re-earnable every season, unlike the lifetime catalog
 * {@see GrantEligibleUnlocksAction} grants once and never again.
 *
 * No ingest hook can trigger this (honoring a rest day is the ABSENCE of an
 * activity, not a new one arriving), so it's invoked opportunistically on the
 * read paths that already compute a {@see SeasonGamificationContext}: the
 * Plan tab's season summary and the badge board.
 *
 * The season track (`season.{id}.track_{N}`, one tier per completed
 * {@see \App\Models\SeasonGoal}) extends that same per-season namespace for the
 * same reason: the 25 lifetime keys are claimed 1:1 by `config/temari_goals.php`
 * and granted once forever, so a track paying out of that catalog would have
 * nothing left to give a returning user.
 */
class GrantSeasonUnlocksAction
{
    public function __construct(
        private readonly SeasonGoalResolver $goalResolver,
    ) {
    }

    /** @return list<string> */
    public function __invoke(User $user, Season $season, SeasonGamificationContext $ctx): array
    {
        $already = UserUnlock::query()
            ->where('user_id', $user->id)
            ->where('unlock_key', 'like', "season.{$season->id}.%")
            ->pluck('unlock_key')
            ->all();

        $eligible = [
            ...$this->restHonoredKeys($season, $ctx),
            ...$this->trackKeys($user, $season, $ctx),
        ];

        $new = array_values(array_diff($eligible, $already));
        if ($new === []) {
            return [];
        }

        $now = Carbon::now();
        UserUnlock::query()->insert(array_map(fn (string $key): array => [
            'user_id' => $user->id,
            'unlock_key' => $key,
            'unlocked_at' => $now,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $new));

        return $new;
    }

    /** @return list<string> */
    private function restHonoredKeys(Season $season, SeasonGamificationContext $ctx): array
    {
        $keys = [];
        foreach (SeasonService::REST_HONORED_THRESHOLDS as $threshold) {
            if ($ctx->restHonored >= $threshold) {
                $keys[] = "season.{$season->id}.rest_honored_{$threshold}";
            }
        }

        return $keys;
    }

    /**
     * One track tier per completed season goal. Goal targets are generated
     * scaled to the season's own length, so a short race-oriented season and a
     * 12-week self-scaled one both run a comparable 0..n track.
     *
     * @return list<string>
     */
    private function trackKeys(User $user, Season $season, SeasonGamificationContext $ctx): array
    {
        $completed = 0;
        foreach ($this->goalResolver->forSeason($user, $season, $ctx) as $goal) {
            if ($goal['is_completed']) {
                $completed++;
            }
        }

        $keys = [];
        for ($tier = 1; $tier <= $completed; $tier++) {
            $keys[] = "season.{$season->id}.track_{$tier}";
        }

        return $keys;
    }
}
