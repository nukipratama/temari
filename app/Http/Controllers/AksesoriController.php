<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\EquipAksesoriRequest;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Gamification\EquippedAccessories;
use App\Services\Gamification\GoalResolver;
use App\Support\SharedPropCacheKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class AksesoriController extends Controller
{
    public function __construct(
        private readonly EquippedAccessories $equipped,
        private readonly GoalResolver $goals,
    ) {
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $unlocks = UserUnlock::query()
            ->where('user_id', $user->id)
            ->get();

        $catalog = (array) config('temari_unlocks', []);
        $goalsCatalog = (array) config('temari_goals', []);
        // Reuses GoalResolver's server-side current/target computation — the
        // same one the retired Goals.tsx page used — as live progress on the
        // locked-item cards here instead.
        $progressByKey = collect($this->goals->forUser($user))->keyBy('id');

        $unlockedKeys = $unlocks->pluck('unlock_key')->all();
        $equippedByKey = $unlocks->keyBy('unlock_key');

        $items = [];
        foreach ($catalog as $key => $meta) {
            if (! \is_array($meta)) {
                continue;
            }
            $slot = $this->equipped->slotFor((string) $key);
            $unlock = $equippedByKey->get((string) $key);
            $progress = $progressByKey->get((string) $key);
            $items[] = [
                'unlock_key' => (string) $key,
                'slot' => $slot,
                'rarity' => (string) ($meta['rarity'] ?? 'common'),
                'name' => (string) ($meta['name'] ?? $key),
                'icon' => (string) ($meta['icon'] ?? 'mdi:medal'),
                'description' => (string) ($meta['description'] ?? ''),
                'criteria' => (string) ($goalsCatalog[$key]['description'] ?? ''),
                'unlocked' => \in_array((string) $key, $unlockedKeys, true),
                'equipped' => $unlock !== null && (bool) $unlock->equipped,
                'current' => $progress['current'] ?? 0,
                'target' => $progress['target'] ?? 0,
                'unit' => $progress['unit'] ?? '',
            ];
        }

        return Inertia::render('Collection/Accessories', [
            'items' => $items,
            'equipped' => $this->equipped->resolve($unlocks),
        ]);
    }

    public function equip(EquipAksesoriRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $key = $request->unlockKey();
        $slot = $this->equipped->slotFor($key);

        $unlock = UserUnlock::query()
            ->where('user_id', $user->id)
            ->where('unlock_key', $key)
            ->first();

        if ($unlock === null) {
            return back()->withErrors(['unlock_key' => 'This item isn\'t unlocked yet.']);
        }

        if ($slot === null) {
            return back()->withErrors(['unlock_key' => 'This item has no slot.']);
        }

        /** @var array<string, mixed> $catalog */
        $catalog = (array) config('temari_unlocks', []);
        $siblingKeys = array_values(array_filter(
            array_keys($catalog),
            fn (string $k): bool => $this->equipped->slotFor($k) === $slot && $k !== $key,
        ));

        DB::transaction(function () use ($user, $siblingKeys, $unlock): void {
            UserUnlock::query()
                ->where('user_id', $user->id)
                ->whereIn('unlock_key', $siblingKeys)
                ->update(['equipped' => false]);

            $unlock->forceFill(['equipped' => true])->save();
        });

        // The sibling unequip is a mass update, so no model event fires for it.
        // Busted after the commit so a concurrent read cannot re-warm the cache
        // from the pre-swap rows.
        SharedPropCacheKey::EquippedAccessories->forget($user->id);

        return back();
    }
}
