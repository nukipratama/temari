<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('shares the equipped accessories on every authenticated page', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.ikat_kepala_epik']);
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_emas']);
    // Unlocked but not equipped — must not leak into the shared set.
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.ikat_kepala_legendaris', 'equipped' => false]);

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('equippedAccessories.ikat_kepala', 'accessory.ikat_kepala_epik')
            ->where('equippedAccessories.medal', 'accessory.medal_emas')
            ->where('equippedAccessories.aura', null));
});

it('shares an empty equipped set when nothing is equipped', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/rekor')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('equippedAccessories.ikat_kepala', null)
            ->where('equippedAccessories.medal', null));
});
