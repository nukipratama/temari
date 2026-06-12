<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Database\Eloquent\Collection;

/**
 * Resolves which accessories a user has equipped into the shape the Temari
 * mascot renders ({@see resources/js/components/temari/TemariProto.tsx}). One
 * source of truth shared by the Aksesori page and the global Inertia prop, so
 * the equipped look stays consistent everywhere the mascot appears.
 */
class EquippedAccessories
{
    /**
     * The ordered list of valid equipment slots.
     *
     * @return list<string>
     */
    public function slots(): array
    {
        return ['medal', 'ikat_kepala', 'kaus', 'celana', 'sepatu', 'aura'];
    }

    /**
     * @return array<string, string|null>
     */
    public function forUser(?User $user): array
    {
        if ($user === null) {
            return $this->empty();
        }

        return $this->resolve(
            UserUnlock::query()->where('user_id', $user->id)->get(),
        );
    }

    /**
     * @param  Collection<int, UserUnlock>  $unlocks
     * @return array<string, string|null>
     */
    public function resolve(Collection $unlocks): array
    {
        $catalog = $this->catalogLookup();
        $equipped = $unlocks->filter(fn (UserUnlock $u): bool => (bool) $u->equipped);

        $result = $this->empty();

        foreach ($this->slots() as $slot) {
            $result[$slot] = $this->equippedKeyForSlot($equipped, $slot, $catalog);
        }

        return $result;
    }

    /**
     * @param  Collection<int, UserUnlock>  $equipped
     * @param  array<string, array{slot?: string}>  $catalog
     */
    private function equippedKeyForSlot(Collection $equipped, string $slot, array $catalog): ?string
    {
        $item = $equipped->first(function (UserUnlock $u) use ($catalog, $slot): bool {
            $meta = $catalog[$u->unlock_key] ?? null;

            return isset($meta['slot']) && $meta['slot'] === $slot;
        });

        return $item?->unlock_key;
    }

    public function slotFor(string $key): ?string
    {
        $catalog = $this->catalogLookup();
        $meta = $catalog[$key] ?? null;

        return $meta['slot'] ?? null;
    }

    /** @return array<string, array{slot?: string}> */
    private function catalogLookup(): array
    {
        return (array) config('temari_unlocks', []);
    }

    /**
     * @return array<string, null>
     */
    private function empty(): array
    {
        return array_fill_keys($this->slots(), null);
    }
}
