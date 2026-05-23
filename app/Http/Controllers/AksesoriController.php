<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AksesoriController extends Controller
{
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
            $slot = $this->slotFor((string) $key);
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
            'equipped' => $this->resolveEquipped($unlocks),
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
        $slot = $this->slotFor($key);

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
            fn (string $k): bool => $this->slotFor($k) === $slot && $k !== $key,
        ));

        UserUnlock::query()
            ->where('user_id', $user->id)
            ->whereIn('unlock_key', $siblingKeys)
            ->update(['equipped' => false]);

        $unlock->forceFill(['equipped' => true])->save();

        return back();
    }

    private function slotFor(string $key): ?string
    {
        $without = str_replace('accessory.', '', $key);
        if (str_starts_with($without, 'headband_')) {
            return 'headband';
        }
        if (str_starts_with($without, 'medal_')) {
            return 'medal';
        }
        if (str_starts_with($without, 'pita_') || str_starts_with($without, 'weekly_streak_')) {
            return 'pita';
        }
        if (str_starts_with($without, 'aura_')) {
            return 'aura';
        }

        return null;
    }

    /**
     * @param  Collection<int, UserUnlock>  $unlocks
     * @return array{headband: ?string, medal: ?string, pita: bool, aura: bool}
     */
    private function resolveEquipped(Collection $unlocks): array
    {
        $byEquipped = $unlocks->filter(fn (UserUnlock $u): bool => (bool) $u->equipped);

        $headband = $byEquipped->first(fn (UserUnlock $u): bool => $this->slotFor($u->unlock_key) === 'headband');
        $medal = $byEquipped->first(fn (UserUnlock $u): bool => $this->slotFor($u->unlock_key) === 'medal');
        $pita = $byEquipped->contains(fn (UserUnlock $u): bool => $this->slotFor($u->unlock_key) === 'pita');
        $aura = $byEquipped->contains(fn (UserUnlock $u): bool => $this->slotFor($u->unlock_key) === 'aura');

        return [
            'headband' => $headband !== null ? $this->headbandVariant($headband->unlock_key) : null,
            'medal' => $medal !== null ? $this->medalVariant($medal->unlock_key) : null,
            'pita' => $pita,
            'aura' => $aura,
        ];
    }

    private function headbandVariant(string $key): string
    {
        return match ($key) {
            'accessory.headband_legendaris' => 'legendaris',
            'accessory.headband_epik' => 'epik',
            default => 'ember',
        };
    }

    private function medalVariant(string $key): ?string
    {
        return match ($key) {
            'accessory.medal_gold' => 'emas',
            'accessory.medal_first_pr' => 'pertama',
            default => null,
        };
    }
}
