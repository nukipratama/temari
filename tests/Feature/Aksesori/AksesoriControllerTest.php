<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the catalog + equipped slots', function (): void {
    $user = User::factory()->create();

    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.headband_epik',
        'equipped' => true,
    ]);
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.medal_first_pr',
        'equipped' => false,
    ]);

    $this->actingAs($user)->get('/aksesori')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Koleksi/Aksesori')
            ->where('equipped.headband', 'epik')
            ->where('equipped.medal', null)
            ->where('equipped.pita', false)
            ->where('equipped.aura', false)
            ->has('items', 5));
});

it('equips a headband + un-equips the previous sibling', function (): void {
    $user = User::factory()->create();

    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.headband_epik',
        'equipped' => true,
    ]);
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.headband_legendaris',
        'equipped' => false,
    ]);

    $this->actingAs($user)
        ->post('/api/aksesori/equip', ['unlock_key' => 'accessory.headband_legendaris'])
        ->assertRedirect();

    expect(UserUnlock::query()
        ->where('user_id', $user->id)
        ->where('unlock_key', 'accessory.headband_epik')
        ->value('equipped'))->toBeFalse();
    expect(UserUnlock::query()
        ->where('user_id', $user->id)
        ->where('unlock_key', 'accessory.headband_legendaris')
        ->value('equipped'))->toBeTrue();
});

it('refuses to equip an accessory the user has not unlocked', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/aksesori/equip', ['unlock_key' => 'accessory.medal_gold'])
        ->assertSessionHasErrors(['unlock_key']);
});
