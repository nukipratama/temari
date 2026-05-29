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
     * @return array{headband: ?string, medal: ?string, pita: bool, aura: bool}
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
     * @return array{headband: ?string, medal: ?string, pita: bool, aura: bool}
     */
    public function resolve(Collection $unlocks): array
    {
        $equipped = $unlocks->filter(fn (UserUnlock $u): bool => (bool) $u->equipped);

        $headband = $equipped->first(fn (UserUnlock $u): bool => $this->slotFor($u->unlock_key) === 'headband');
        $medal = $equipped->first(fn (UserUnlock $u): bool => $this->slotFor($u->unlock_key) === 'medal');

        return [
            'headband' => $headband !== null ? $this->headbandVariant($headband->unlock_key) : null,
            'medal' => $medal !== null ? $this->medalVariant($medal->unlock_key) : null,
            'pita' => $equipped->contains(fn (UserUnlock $u): bool => $this->slotFor($u->unlock_key) === 'pita'),
            'aura' => $equipped->contains(fn (UserUnlock $u): bool => $this->slotFor($u->unlock_key) === 'aura'),
        ];
    }

    public function slotFor(string $key): ?string
    {
        $without = str_replace('accessory.', '', $key);

        return match (true) {
            str_starts_with($without, 'headband_') => 'headband',
            str_starts_with($without, 'medal_') => 'medal',
            str_starts_with($without, 'pita_'), str_starts_with($without, 'weekly_streak_') => 'pita',
            str_starts_with($without, 'aura_') => 'aura',
            default => null,
        };
    }

    /**
     * @return array{headband: null, medal: null, pita: false, aura: false}
     */
    private function empty(): array
    {
        return ['headband' => null, 'medal' => null, 'pita' => false, 'aura' => false];
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
