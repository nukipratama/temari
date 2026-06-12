<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('renders the catalog + equipped slots', function (): void {
    $user = User::factory()->create();

    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.ikat_kepala_epik']);
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.medal_pertama',
        'equipped' => false,
    ]);

    $this->actingAs($user)->get('/aksesori')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Koleksi/Aksesori')
            ->where('equipped.ikat_kepala', 'accessory.ikat_kepala_epik')
            ->where('equipped.medal', null)
            ->where('equipped.aura', null)
            ->has('items', 24));
});

it('equips an ikat_kepala + un-equips the previous sibling', function (): void {
    $user = User::factory()->create();

    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.ikat_kepala_epik']);
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.ikat_kepala_legendaris',
        'equipped' => false,
    ]);

    $this->actingAs($user)
        ->post('/api/aksesori/equip', ['unlock_key' => 'accessory.ikat_kepala_legendaris'])
        ->assertRedirect();

    expect(UserUnlock::query()
        ->where('user_id', $user->id)
        ->where('unlock_key', 'accessory.ikat_kepala_epik')
        ->value('equipped'))->toBeFalse();
    expect(UserUnlock::query()
        ->where('user_id', $user->id)
        ->where('unlock_key', 'accessory.ikat_kepala_legendaris')
        ->value('equipped'))->toBeTrue();
});

it('refuses to equip an accessory the user has not unlocked', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/aksesori/equip', ['unlock_key' => 'accessory.medal_emas'])
        ->assertSessionHasErrors(['unlock_key']);
});

it('refuses to equip an unlock that does not belong to any slot', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'achievement.first_run',
        'equipped' => false,
    ]);

    $this->actingAs($user)
        ->post('/api/aksesori/equip', ['unlock_key' => 'achievement.first_run'])
        ->assertSessionHasErrors(['unlock_key']);
});

it('resolves equipped unlock keys per slot', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.ikat_kepala_legendaris']);
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_emas']);

    $this->actingAs($user)->get('/aksesori')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('equipped.ikat_kepala', 'accessory.ikat_kepala_legendaris')
            ->where('equipped.medal', 'accessory.medal_emas'));
});

it('resolves medal slot when medal_pertama is equipped', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_pertama']);

    $this->actingAs($user)->get('/aksesori')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('equipped.medal', 'accessory.medal_pertama'));
});

it('resolves aura slot when an aura unlock is equipped', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.aura_pemanasan']);

    $this->actingAs($user)->get('/aksesori')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('equipped.aura', 'accessory.aura_pemanasan'));
});
