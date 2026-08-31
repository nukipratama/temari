<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Inertia\GamificationProps;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gamificationPropsFor(?User $user): array
{
    return app(GamificationProps::class)->forUser($user);
}

it('keeps every prop a closure so a partial reload can skip it', function (): void {
    $props = gamificationPropsFor(User::factory()->create());

    foreach (['equippedAccessories', 'activeRace'] as $key) {
        expect($props[$key])->toBeInstanceOf(Closure::class);
    }
});

it('reports what the mascot is wearing', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_gold']);

    expect((gamificationPropsFor($user)['equippedAccessories'])())
        ->toMatchArray(['medal' => 'accessory.medal_gold']);
});

it('gives a guest the empty accessory set', function (): void {
    $props = gamificationPropsFor(null);

    expect(($props['equippedAccessories'])())->toBe([
            'medal' => null,
            'headband' => null,
            'shirt' => null,
            'shorts' => null,
            'shoes' => null,
            'aura' => null,
        ]);
});
