<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\Badge;
use App\Enums\Rarity;
use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

/**
 * Recomputes eligible unlocks for a user and persists new ones. Idempotent:
 * existing unlock_key rows are left alone, so calling this on every event is
 * safe.
 *
 * Each criterion reads from materialized data (badges, PR rows, weekly
 * snapshots, activity_details) rather than re-computing thresholds, so the
 * engine stays cheap even as the catalog grows.
 *
 * @return list<string> unlock keys newly granted in this call
 */
class UnlockEngine
{
    /** Keys that trigger the full-screen unlock takeover instead of the toast. */
    private const array MAJOR_KEYS = [
        'accessory.ikat_kepala_legendaris',
        'accessory.kaus_legendaris',
        'accessory.sepatu_legendaris',
        'accessory.aura_jagoan',
    ];

    /** @var list<string>|null */
    private static ?array $allKeys = null;

    /** @return list<string> */
    private static function allKeys(): array
    {
        return self::$allKeys ??= array_keys((array) config('temari_unlocks', []));
    }

    /** @return list<string> */
    public function grantEligible(User $user): array
    {
        $already = UserUnlock::query()
            ->where('user_id', $user->id)
            ->pluck('unlock_key')
            ->all();

        // Once every defined accessory is unlocked, skip the eligibility
        // queries — they're moot.
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

        // Flash the first new unlock for the toast on the next request. Only
        // do this when a session is active — background jobs / CLI ingests
        // don't have one and would crash here.
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
            $keys[] = 'accessory.medal_pertama';
        }
        if ($ctx->prCount >= 5) {
            $keys[] = 'accessory.medal_emas';
        }
        if ($ctx->prCount >= 10) {
            $keys[] = 'accessory.medal_perak';
        }
        if ($ctx->prCount >= 20) {
            $keys[] = 'accessory.medal_platina';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleIkatKepala(GamificationContext $ctx): array
    {
        $keys = [];
        $rc = $ctx->rarityCounts;

        if (($rc[Rarity::Uncommon->value] ?? 0) >= 3) {
            $keys[] = 'accessory.ikat_kepala_berkesan';
        }
        if (($rc[Rarity::Rare->value] ?? 0) >= 3) {
            $keys[] = 'accessory.ikat_kepala_langka';
        }
        if (($rc[Rarity::Epic->value] ?? 0) >= 3) {
            $keys[] = 'accessory.ikat_kepala_epik';
        }
        if (($rc[Rarity::Legendary->value] ?? 0) >= 1) {
            $keys[] = 'accessory.ikat_kepala_legendaris';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleKaus(GamificationContext $ctx): array
    {
        $keys = [];

        if ($ctx->activityCount >= 1) {
            $keys[] = 'accessory.kaus_pemula';
        }
        if (($ctx->badgeCounts[Badge::AnakPagi->value] ?? 0) >= 5) {
            $keys[] = 'accessory.kaus_pagi';
        }
        if (($ctx->badgeCounts[Badge::PejuangHujan->value] ?? 0) >= 3) {
            $keys[] = 'accessory.kaus_hujan';
        }
        if ($ctx->activityCount >= 50) {
            $keys[] = 'accessory.kaus_legendaris';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleCelana(GamificationContext $ctx): array
    {
        $keys = [];

        if ($ctx->fiveKPlus >= 1) {
            $keys[] = 'accessory.celana_ringan';
        }
        if ($ctx->tenKPlus >= 1) {
            $keys[] = 'accessory.celana_jarak';
        }
        if (($ctx->badgeCounts[Badge::NegativeSplit->value] ?? 0) >= 3) {
            $keys[] = 'accessory.celana_split';
        }
        if ($ctx->halfMarathon >= 1) {
            $keys[] = 'accessory.celana_maraton';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleSepatu(GamificationContext $ctx): array
    {
        $keys = [];

        if ($ctx->activityCount >= 10) {
            $keys[] = 'accessory.sepatu_basic';
        }

        if ($ctx->fastPace >= 1) {
            $keys[] = 'accessory.sepatu_cepat';
        }

        if ($ctx->tenKPlus >= 5) {
            $keys[] = 'accessory.sepatu_tahan';
        }
        if ($ctx->totalDistanceM >= 1_000_000) {
            $keys[] = 'accessory.sepatu_legendaris';
        }

        return $keys;
    }

    /** @return list<string> */
    private function eligibleAura(GamificationContext $ctx): array
    {
        $keys = [];

        if ($ctx->twoWeekStreak >= 2) {
            $keys[] = 'accessory.aura_pemanasan';
        }
        if (($ctx->badgeCounts[Badge::HariPanas->value] ?? 0) >= 3) {
            $keys[] = 'accessory.aura_gerah';
        }
        if (($ctx->badgeCounts[Badge::Z2Master->value] ?? 0) >= 5) {
            $keys[] = 'accessory.aura_tenang';
        }
        if (($ctx->rarityCounts[Rarity::Legendary->value] ?? 0) >= 3) {
            $keys[] = 'accessory.aura_jagoan';
        }

        return $keys;
    }
}
