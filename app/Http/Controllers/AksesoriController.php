<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Gamification\EquippedAccessories;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AksesoriController extends Controller
{
    public function __construct(private readonly EquippedAccessories $equipped)
    {
    }

    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        $unlocks = UserUnlock::query()
            ->where('user_id', $user->id)
            ->get();

        $catalog = (array) config('temari_unlocks', []);

        $unlockedKeys = $unlocks->pluck('unlock_key')->all();
        $equippedByKey = $unlocks->keyBy('unlock_key');

        $items = [];
        foreach ($catalog as $key => $meta) {
            if (! \is_array($meta)) {
                continue;
            }
            $slot = $this->equipped->slotFor((string) $key);
            $unlock = $equippedByKey->get((string) $key);
            $items[] = [
                'unlock_key' => (string) $key,
                'slot' => $slot,
                'name' => (string) ($meta['name'] ?? $key),
                'icon' => (string) ($meta['icon'] ?? 'mdi:medal'),
                'description' => (string) ($meta['description'] ?? ''),
                'criteria' => (string) ($meta['criteria'] ?? ''),
                'unlocked' => \in_array((string) $key, $unlockedKeys, true),
                'equipped' => $unlock !== null && (bool) $unlock->equipped,
            ];
        }

        return Inertia::render('Koleksi/Aksesori', [
            'items' => $items,
            'equipped' => $this->equipped->resolve($unlocks),
        ]);
    }

    public function equip(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'unlock_key' => ['required', 'string'],
        ]);

        $key = (string) $validated['unlock_key'];
        $slot = $this->equipped->slotFor($key);

        $unlock = UserUnlock::query()
            ->where('user_id', $user->id)
            ->where('unlock_key', $key)
            ->first();

        if ($unlock === null) {
            return back()->withErrors(['unlock_key' => 'Aksesori belum kebuka.']);
        }

        if ($slot === null) {
            return back()->withErrors(['unlock_key' => 'Aksesori ini gak punya slot.']);
        }

        /** @var array<string, mixed> $catalog */
        $catalog = (array) config('temari_unlocks', []);
        $siblingKeys = array_values(array_filter(
            array_keys($catalog),
            fn (string $k): bool => $this->equipped->slotFor($k) === $slot && $k !== $key,
        ));

        UserUnlock::query()
            ->where('user_id', $user->id)
            ->whereIn('unlock_key', $siblingKeys)
            ->update(['equipped' => false]);

        $unlock->forceFill(['equipped' => true])->save();

        return back();
    }
}
