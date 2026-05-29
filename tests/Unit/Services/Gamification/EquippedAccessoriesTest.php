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

it('maps unlock keys to mascot slots', function (string $key, ?string $slot): void {
    expect($this->service->slotFor($key))->toBe($slot);
})->with([
    ['accessory.headband_legendaris', 'headband'],
    ['accessory.headband_epik', 'headband'],
    ['accessory.medal_gold', 'medal'],
    ['accessory.medal_first_pr', 'medal'],
    ['accessory.weekly_streak_4', 'pita'],
    ['accessory.aura_something', 'aura'],
    ['accessory.unknown_thing', null],
]);

it('returns an empty equipped set for a null user', function (): void {
    expect($this->service->forUser(null))->toBe([
        'headband' => null,
        'medal' => null,
        'pita' => false,
        'aura' => false,
    ]);
});

it('returns an empty equipped set when nothing is equipped', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.headband_legendaris',
        'equipped' => false,
    ]);

    expect($this->service->forUser($user))->toBe([
        'headband' => null,
        'medal' => null,
        'pita' => false,
        'aura' => false,
    ]);
});

it('resolves equipped accessories into mascot variants', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.headband_legendaris', 'equipped' => true]);
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_gold', 'equipped' => true]);
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.weekly_streak_4', 'equipped' => true]);
    // An unlocked-but-unequipped medal must not leak into the result.
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_first_pr', 'equipped' => false]);

    expect($this->service->forUser($user))->toBe([
        'headband' => 'legendaris',
        'medal' => 'emas',
        'pita' => true,
        'aura' => false,
    ]);
});
