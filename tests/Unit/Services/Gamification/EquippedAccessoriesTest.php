<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Gamification\EquippedAccessories;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = new EquippedAccessories();
});

it('maps unlock keys to slots via the catalog config', function (string $key, ?string $slot): void {
    expect($this->service->slotFor($key))->toBe($slot);
})->with([
    ['accessory.headband_legendary', 'headband'],
    ['accessory.headband_epic', 'headband'],
    ['accessory.medal_gold', 'medal'],
    ['accessory.medal_first', 'medal'],
    ['accessory.shirt_beginner', 'shirt'],
    ['accessory.shorts_lightweight', 'shorts'],
    ['accessory.shoes_basic', 'shoes'],
    ['accessory.aura_warmup', 'aura'],
    ['accessory.unknown_thing', null],
]);

it('returns an empty equipped set for a null user', function (): void {
    $result = $this->service->forUser(null);
    expect($result)->toBe([
        'medal' => null,
        'headband' => null,
        'shirt' => null,
        'shorts' => null,
        'shoes' => null,
        'aura' => null,
    ]);
});

it('returns an empty equipped set when nothing is equipped', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.headband_legendary',
        'equipped' => false,
    ]);

    $result = $this->service->forUser($user);
    expect($result)->toBe([
        'medal' => null,
        'headband' => null,
        'shirt' => null,
        'shorts' => null,
        'shoes' => null,
        'aura' => null,
    ]);
});

it('picks one equipped item when two items compete for the same slot', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create([
        'unlock_key' => 'accessory.headband_legendary',
    ]);
    UserUnlock::factory()->for($user)->equipped()->create([
        'unlock_key' => 'accessory.headband_epic',
    ]);

    $result = $this->service->forUser($user);
    expect($result['headband'])->not->toBeNull();
});

it('resolves equipped accessories into unlock keys per slot', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.headband_legendary']);
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_gold']);
    // An unlocked-but-unequipped medal must not leak into the result.
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_first', 'equipped' => false]);

    expect($this->service->forUser($user))->toBe([
        'medal' => 'accessory.medal_gold',
        'headband' => 'accessory.headband_legendary',
        'shirt' => null,
        'shorts' => null,
        'shoes' => null,
        'aura' => null,
    ]);
});
