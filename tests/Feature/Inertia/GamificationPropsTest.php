<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Inertia\GamificationProps;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gamificationPropsFor(?User $user): array
{
    return app(GamificationProps::class)->forUser($user);
}

it('keeps every prop a closure so a partial reload can skip it', function (): void {
    $props = gamificationPropsFor(User::factory()->create());

    foreach (['activeRace'] as $key) {
        expect($props[$key])->toBeInstanceOf(Closure::class);
    }
});
