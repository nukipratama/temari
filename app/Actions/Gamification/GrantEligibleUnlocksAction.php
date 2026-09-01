<?php

declare(strict_types=1);

namespace App\Actions\Gamification;

use App\Models\User;
use App\Models\UserUnlock;
use App\Notifications\UnlockGrantedNotification;
use App\Services\Gamification\GamificationContext;
use App\Services\Gamification\GoalResolver;
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

    public function __construct(
        private readonly GoalResolver $goalResolver = new GoalResolver(),
    ) {
    }

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

        $celebrations = array_values(array_filter(array_map($this->celebration(...), $new)));

        // Every new unlock goes to the inbox, so one granted during a background
        // ingest is still there to be celebrated later.
        foreach ($celebrations as $celebration) {
            $user->notify(new UnlockGrantedNotification($celebration));
        }

        // Flash the first new unlock for the toast on the next request.
        // Session::isStarted() guards background jobs / CLI ingests, which
        // have no session and would crash here.
        if (Session::isStarted() && $celebrations !== []) {
            Session::flash('unlock', $celebrations[0]);
        }

        return $new;
    }

    /**
     * The celebration payload shared by the immediate toast and the inbox row,
     * or null for a key with no catalog entry.
     *
     * @return array{unlock_key: string, name: string, icon: string, is_major: bool}|null
     */
    public function celebration(string $key): ?array
    {
        $catalog = config('temari_unlocks', []);
        $def = is_array($catalog) ? ($catalog[$key] ?? null) : null;
        if (! is_array($def)) {
            return null;
        }

        return [
            'unlock_key' => $key,
            'name' => (string) ($def['name'] ?? $key),
            'icon' => (string) ($def['icon'] ?? 'mdi:medal'),
            'is_major' => \in_array($key, self::MAJOR_KEYS, true),
        ];
    }

    /**
     * Evaluates every entry in the goal catalog against the context generically:
     * grant once `current >= target`, the same comparator {@see GoalResolver}
     * uses for progress bars. A new unlock needs only a catalog entry here, no
     * PHP change.
     *
     * @return list<string>
     */
    private function computeEligible(User $user): array
    {
        $ctx = GamificationContext::forUser($user);

        /** @var array<string, array{metric: string, metric_key?: string, target: int|float}> $catalog */
        $catalog = (array) config('temari_goals', []);

        $keys = [];
        foreach ($catalog as $key => $goal) {
            $current = $this->goalResolver->currentValue($ctx, $goal['metric'], $goal['metric_key'] ?? '');
            if ($current >= $goal['target']) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }
}
