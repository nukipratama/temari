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
    ['accessory.ikat_kepala_legendaris', 'ikat_kepala'],
    ['accessory.ikat_kepala_epik', 'ikat_kepala'],
    ['accessory.medal_emas', 'medal'],
    ['accessory.medal_pertama', 'medal'],
    ['accessory.kaus_pemula', 'kaus'],
    ['accessory.celana_ringan', 'celana'],
    ['accessory.sepatu_basic', 'sepatu'],
    ['accessory.aura_pemanasan', 'aura'],
    ['accessory.unknown_thing', null],
]);

it('returns an empty equipped set for a null user', function (): void {
    $result = $this->service->forUser(null);
    expect($result)->toBe([
        'medal' => null,
        'ikat_kepala' => null,
        'kaus' => null,
        'celana' => null,
        'sepatu' => null,
        'aura' => null,
    ]);
});

it('returns an empty equipped set when nothing is equipped', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->create([
        'unlock_key' => 'accessory.ikat_kepala_legendaris',
        'equipped' => false,
    ]);

    $result = $this->service->forUser($user);
    expect($result)->toBe([
        'medal' => null,
        'ikat_kepala' => null,
        'kaus' => null,
        'celana' => null,
        'sepatu' => null,
        'aura' => null,
    ]);
});

it('resolves equipped accessories into unlock keys per slot', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.ikat_kepala_legendaris']);
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_emas']);
    // An unlocked-but-unequipped medal must not leak into the result.
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_pertama', 'equipped' => false]);

    expect($this->service->forUser($user))->toBe([
        'medal' => 'accessory.medal_emas',
        'ikat_kepala' => 'accessory.ikat_kepala_legendaris',
        'kaus' => null,
        'celana' => null,
        'sepatu' => null,
        'aura' => null,
    ]);
});
