<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * Recomputes eligible unlocks for a user and persists new ones. Idempotent:
 * existing unlock_key rows are left alone, so calling this on every event is
 * safe.
 *
 * @return list<string> unlock keys newly granted in this call
 */
class UnlockEngine
{
    private const array ALL_KEYS = [
        'accessory.medal_first_pr',
        'accessory.medal_gold',
        'accessory.headband_legendaris',
        'accessory.headband_epik',
        'accessory.weekly_streak_4',
    ];

    /** @return list<string> */
    public function grantEligible(User $user): array
    {
        $already = UserUnlock::query()
            ->where('user_id', $user->id)
            ->pluck('unlock_key')
            ->all();

        // Once every defined accessory is unlocked, skip the eligibility
        // queries — they're moot.
        if (count(array_diff(self::ALL_KEYS, $already)) === 0) {
            return [];
        }

        $eligible = $this->computeEligible($user);
        $new = array_values(array_diff($eligible, $already));

        if ($new === []) {
            return [];
        }

        $now = Carbon::now();
        $rows = array_map(fn (string $key): array => [
            'user_id' => $user->id,
            'unlock_key' => $key,
            'unlocked_at' => $now,
            'metadata' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $new);

        UserUnlock::query()->insert($rows);

        // Flash the first new unlock for the toast on the next request. Only
        // do this when a session is active — background jobs / CLI ingests
        // don't have one and would crash here.
        if (Session::isStarted()) {
            $firstKey = $new[0];
            $def = config("temari_unlocks.{$firstKey}");
            if (is_array($def)) {
                Session::flash('unlock', [
                    'unlock_key' => $firstKey,
                    'name' => $def['name'] ?? $firstKey,
                    'icon' => $def['icon'] ?? 'mdi:medal',
                ]);
            }
        }

        return $new;
    }

    /**
     * @return list<string>
     */
    private function computeEligible(User $user): array
    {
        $keys = [];

        $prCount = PersonalRecord::query()->where('user_id', $user->id)->count();
        if ($prCount >= 1) {
            $keys[] = 'accessory.medal_first_pr';
        }
        if ($prCount >= 5) {
            $keys[] = 'accessory.medal_gold';
        }

        $legendarisCount = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where('rarity', RunCard::RARITY_LEGENDARIS)
            ->count();
        if ($legendarisCount >= 1) {
            $keys[] = 'accessory.headband_legendaris';
        }

        $epikCount = RunCard::query()
            ->whereHas('activity', fn ($q) => $q->where('user_id', $user->id))
            ->where('rarity', RunCard::RARITY_EPIK)
            ->count();
        if ($epikCount >= 3) {
            $keys[] = 'accessory.headband_epik';
        }

        $streakWeeks = WeeklySnapshot::query()
            ->where('user_id', $user->id)
            ->where('runs', '>', 0)
            ->orderByDesc('week_ending')
            ->limit(4)
            ->count();
        if ($streakWeeks >= 4) {
            $keys[] = 'accessory.weekly_streak_4';
        }

        return $keys;
    }
}
