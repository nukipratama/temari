<?php

declare(strict_types=1);

namespace App\Actions\Gamification;

use App\Enums\Badge;
use App\Enums\Rarity;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Gamification\GamificationContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * Recomputes eligible unlocks for a user and persists new ones. Idempotent:
 * existing unlock_key rows are left alone.
 */
class GrantEligibleUnlocksAction
{
    /** Keys that trigger the full-screen unlock takeover instead of the toast. */
    private const array MAJOR_KEYS = [
        'accessory.headband_legendary',
        'accessory.shirt_legendary',
        'accessory.shoes_legendary',
        'accessory.aura_champion',
    ];

    /** @var list<string>|null */
    private static ?array $allKeys = null;

    /** @return list<string> */
    private static function allKeys(): array
    {
        return self::$allKeys ??= array_keys((array) config('temari_unlocks', []));
    }

    /** @return list<string> */
    public function __invoke(User $user): array
    {
        $already = UserUnlock::query()
            ->where('user_id', $user->id)
            ->pluck('unlock_key')
            ->all();

        if (count(array_diff(self::allKeys(), $already)) === 0) {
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

        // Flash the first new unlock for the toast on the next request.
        // Session::isStarted() guards background jobs / CLI ingests, which
        // have no session and would crash here.
        if (Session::isStarted()) {
            $firstKey = $new[0];
            $catalog = config('temari_unlocks', []);
            $def = is_array($catalog) ? ($catalog[$firstKey] ?? null) : null;
            if (is_array($def)) {
                Session::flash('unlock', [
                    'unlock_key' => $firstKey,
                    'name' => $def['name'] ?? $firstKey,
                    'icon' => $def['icon'] ?? 'mdi:medal',
                    'is_major' => \in_array($firstKey, self::MAJOR_KEYS, true),
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
        $ctx = GamificationContext::forUser($user);

        return [
            ...$this->eligibleMedal($ctx),
            ...$this->eligibleIkatKepala($ctx),
            ...$this->eligibleKaus($ctx),
            ...$this->eligibleCelana($ctx),
            ...$this->eligibleSepatu($ctx),
            ...$this->eligibleAura($ctx),
        ];
    }

    /** @return list<string> */
    private function eligibleMedal(GamificationContext $ctx): array
    {
        $keys = [];
        if ($ctx->prCount >= 1) {
            $keys[] = 'accessory.medal_first';
        }
        if ($ctx->prCount >= 5) {
            $keys[] = 'accessory.medal_gold';
        }
        if ($ctx->prCount >= 10) {
            $keys[] = 'accessory.medal_silver';
        }
        if ($ctx->prCount >= 20) {
            $keys[] = 'accessory.medal_platinum';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleIkatKepala(GamificationContext $ctx): array
    {
        $keys = [];
        $rc = $ctx->rarityCounts;

        if (($rc[Rarity::Uncommon->value] ?? 0) >= 3) {
            $keys[] = 'accessory.headband_uncommon';
        }
        if (($rc[Rarity::Rare->value] ?? 0) >= 3) {
            $keys[] = 'accessory.headband_rare';
        }
        if (($rc[Rarity::Epic->value] ?? 0) >= 3) {
            $keys[] = 'accessory.headband_epic';
        }
        if (($rc[Rarity::Legendary->value] ?? 0) >= 1) {
            $keys[] = 'accessory.headband_legendary';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleKaus(GamificationContext $ctx): array
    {
        $keys = [];

        if ($ctx->activityCount >= 1) {
            $keys[] = 'accessory.shirt_beginner';
        }
        if (($ctx->badgeCounts[Badge::AnakPagi->value] ?? 0) >= 5) {
            $keys[] = 'accessory.shirt_early_bird';
        }
        if (($ctx->badgeCounts[Badge::PejuangHujan->value] ?? 0) >= 3) {
            $keys[] = 'accessory.shirt_rain_warrior';
        }
        if ($ctx->activityCount >= 50) {
            $keys[] = 'accessory.shirt_legendary';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleCelana(GamificationContext $ctx): array
    {
        $keys = [];

        if ($ctx->fiveKPlus >= 1) {
            $keys[] = 'accessory.shorts_lightweight';
        }
        if ($ctx->tenKPlus >= 1) {
            $keys[] = 'accessory.shorts_explorer';
        }
        if (($ctx->badgeCounts[Badge::NegativeSplit->value] ?? 0) >= 3) {
            $keys[] = 'accessory.shorts_negative_split';
        }
        if ($ctx->halfMarathon >= 1) {
            $keys[] = 'accessory.shorts_marathon';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleSepatu(GamificationContext $ctx): array
    {
        $keys = [];

        if ($ctx->activityCount >= 10) {
            $keys[] = 'accessory.shoes_basic';
        }

        if ($ctx->fastPace >= 1) {
            $keys[] = 'accessory.shoes_speed';
        }

        if ($ctx->tenKPlus >= 5) {
            $keys[] = 'accessory.shoes_rugged';
        }
        if ($ctx->totalDistanceM >= 1_000_000) {
            $keys[] = 'accessory.shoes_legendary';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleAura(GamificationContext $ctx): array
    {
        $keys = [];

        if ($ctx->twoWeekStreak >= 2) {
            $keys[] = 'accessory.aura_warmup';
        }
        if (($ctx->badgeCounts[Badge::HariPanas->value] ?? 0) >= 3) {
            $keys[] = 'accessory.aura_heatwave';
        }
        if (($ctx->badgeCounts[Badge::Z2Master->value] ?? 0) >= 5) {
            $keys[] = 'accessory.aura_calm';
        }
        if (($ctx->rarityCounts[Rarity::Legendary->value] ?? 0) >= 3) {
            $keys[] = 'accessory.aura_champion';
        }
        if (($ctx->badgeCounts[Badge::LawanAngin->value] ?? 0) >= 3) {
            $keys[] = 'accessory.aura_windrunner';
        }

        return $keys;
    }
}
