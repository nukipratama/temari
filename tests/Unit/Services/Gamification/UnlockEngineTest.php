<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\UnlockEngine;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Session;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->engine = app(UnlockEngine::class);
});

it('returns empty when nothing has been earned yet', function (): void {
    $user = User::factory()->create();

    expect($this->engine->grantEligible($user))->toBe([]);
});

it('grants accessory.medal_pertama on first PR', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    $granted = $this->engine->grantEligible($user);

    expect($granted)->toContain('accessory.medal_pertama')
        ->and(UserUnlock::query()->where('user_id', $user->id)->pluck('unlock_key')->all())
        ->toContain('accessory.medal_pertama');
});

it('is idempotent — re-running does not duplicate the unlock', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    $this->engine->grantEligible($user);
    $second = $this->engine->grantEligible($user);

    expect($second)->toBe([])
        ->and(UserUnlock::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('short-circuits once every accessory has been unlocked', function (): void {
    $user = User::factory()->create();
    $now = Carbon::now();
    $catalog = (array) config('temari_unlocks', []);
    foreach (array_keys($catalog) as $key) {
        UserUnlock::factory()->for($user)->create([
            'unlock_key' => (string) $key,
            'unlocked_at' => $now,
        ]);
    }

    expect($this->engine->grantEligible($user))->toBe([]);
});

it('grants medal_emas once five PRs are recorded', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->count(5)->state(new Sequence(
        ['category' => '1km'],
        ['category' => '5km'],
        ['category' => '10km'],
        ['category' => '15km'],
        ['category' => 'half_marathon'],
    ))->create();

    expect($this->engine->grantEligible($user))
        ->toContain('accessory.medal_emas');
});

it('grants ikat_kepala_legendaris from a Legendaris run card', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    RunCard::factory()->create([
        'activity_id' => $activity->id,
        'rarity' => Rarity::Legendary,
    ]);

    expect($this->engine->grantEligible($user))
        ->toContain('accessory.ikat_kepala_legendaris');
});

it('grants ikat_kepala_epik after three Epik run cards', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 3) as $_) {
        $activity = Activity::factory()->for($user)->create();
        RunCard::factory()->create([
            'activity_id' => $activity->id,
            'rarity' => Rarity::Epic,
        ]);
    }

    expect($this->engine->grantEligible($user))
        ->toContain('accessory.ikat_kepala_epik');
});

it('flashes a toast payload to the session when a session is active', function (): void {
    Session::start();
    config()->set('temari_unlocks', [
        'accessory.medal_pertama' => ['name' => 'Medali Custom', 'icon' => 'mdi:trophy', 'slot' => 'medal', 'rarity' => 'common'],
    ]);

    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    $this->engine->grantEligible($user);

    $flashed = Session::get('unlock');
    expect($flashed)->toBeArray()
        ->and($flashed['unlock_key'])->toBe('accessory.medal_pertama')
        ->and($flashed['name'])->toBe('Medali Custom')
        ->and($flashed['icon'])->toBe('mdi:trophy');
});

it('skips the flash when the unlock has no config entry', function (): void {
    Session::start();
    config()->set('temari_unlocks', []);

    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    $this->engine->grantEligible($user);

    expect(Session::get('unlock'))->toBeNull();
});

it('falls back to the key + default icon when the config entry omits name and icon', function (): void {
    Session::start();
    config()->set('temari_unlocks', [
        'accessory.medal_pertama' => ['description' => 'x', 'slot' => 'medal', 'rarity' => 'common'],
    ]);

    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    $this->engine->grantEligible($user);

    expect(Session::get('unlock'))->toBe([
        'unlock_key' => 'accessory.medal_pertama',
        'name' => 'accessory.medal_pertama',
        'icon' => 'mdi:medal',
        'is_major' => false,
    ]);
});
