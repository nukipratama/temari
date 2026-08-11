<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('requires authentication for the index', function (): void {
    $this->get('/accessories')->assertRedirect('/login');
});

it('requires authentication for the equip endpoint', function (): void {
    $this->post('/api/accessories/equip', ['unlock_key' => 'accessory.medal_first'])->assertRedirect('/login');
});

it('renders the catalog + equipped slots', function (): void {
    $user = User::factory()->create();

    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.headband_epic']);
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.medal_first',
        'equipped' => false,
    ]);

    $this->actingAs($user)->get('/accessories')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Collection/Accessories')
            ->where('equipped.headband', 'accessory.headband_epic')
            ->where('equipped.medal', null)
            ->where('equipped.aura', null)
            ->has('items', 25)
            ->has('items.0.current')
            ->has('items.0.target')
            ->has('items.0.unit'));
});

it('reuses GoalResolver to show live progress on a locked item', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/accessories')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('items.0.unlock_key', 'accessory.medal_first')
            ->where('items.0.target', 1)
            ->where('items.0.unit', 'PR')
            ->where('items.0.current', 0));
});

it('equips a headband + un-equips the previous sibling', function (): void {
    $user = User::factory()->create();

    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.headband_epic']);
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.headband_legendary',
        'equipped' => false,
    ]);

    $this->actingAs($user)
        ->post('/api/accessories/equip', ['unlock_key' => 'accessory.headband_legendary'])
        ->assertRedirect();

    expect(UserUnlock::query()
        ->where('user_id', $user->id)
        ->where('unlock_key', 'accessory.headband_epic')
        ->value('equipped'))->toBeFalse();
    expect(UserUnlock::query()
        ->where('user_id', $user->id)
        ->where('unlock_key', 'accessory.headband_legendary')
        ->value('equipped'))->toBeTrue();
});

it('rejects an equip request missing the unlock key', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->postJson('/api/accessories/equip', [])
        ->assertStatus(422);
});

it('refuses to equip an accessory the user has not unlocked', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/api/accessories/equip', ['unlock_key' => 'accessory.medal_gold'])
        ->assertSessionHasErrors(['unlock_key']);
});

it('refuses to equip an unlock that does not belong to any slot', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'achievement.first_run',
        'equipped' => false,
    ]);

    $this->actingAs($user)
        ->post('/api/accessories/equip', ['unlock_key' => 'achievement.first_run'])
        ->assertSessionHasErrors(['unlock_key']);
});

it('resolves equipped unlock keys per slot', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.headband_legendary']);
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_gold']);

    $this->actingAs($user)->get('/accessories')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('equipped.headband', 'accessory.headband_legendary')
            ->where('equipped.medal', 'accessory.medal_gold'));
});

it('resolves medal slot when medal_first is equipped', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_first']);

    $this->actingAs($user)->get('/accessories')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('equipped.medal', 'accessory.medal_first'));
});

it('resolves aura slot when an aura unlock is equipped', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.aura_warmup']);

    $this->actingAs($user)->get('/accessories')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page->where('equipped.aura', 'accessory.aura_warmup'));
});
