<?php

declare(strict_types=1);

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

it('returns empty when nothing has been earned yet', function (): void {
    $user = User::factory()->create();

    expect(app(UnlockEngine::class)->grantEligible($user))->toBe([]);
});

it('grants accessory.medal_first_pr on first PR', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    $granted = app(UnlockEngine::class)->grantEligible($user);

    expect($granted)->toContain('accessory.medal_first_pr')
        ->and(UserUnlock::query()->where('user_id', $user->id)->pluck('unlock_key')->all())
        ->toContain('accessory.medal_first_pr');
});

it('is idempotent — re-running does not duplicate the unlock', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    app(UnlockEngine::class)->grantEligible($user);
    $second = app(UnlockEngine::class)->grantEligible($user);

    expect($second)->toBe([])
        ->and(UserUnlock::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('short-circuits once every accessory has been unlocked', function (): void {
    $user = User::factory()->create();
    $now = Carbon::now();
    $allKeys = [
        'accessory.medal_first_pr',
        'accessory.medal_gold',
        'accessory.headband_legendaris',
        'accessory.headband_epik',
        'accessory.weekly_streak_4',
    ];
    foreach ($allKeys as $key) {
        UserUnlock::factory()->for($user)->create([
            'unlock_key' => $key,
            'unlocked_at' => $now,
        ]);
    }

    expect(app(UnlockEngine::class)->grantEligible($user))->toBe([]);
});

it('grants medal_gold once five PRs are recorded', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->count(5)->state(new Sequence(
        ['category' => '1km'],
        ['category' => '5km'],
        ['category' => '10km'],
        ['category' => '15km'],
        ['category' => '21km'],
    ))->create();

    expect(app(UnlockEngine::class)->grantEligible($user))
        ->toContain('accessory.medal_gold');
});

it('grants headband_legendaris from a Legendaris run card', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    RunCard::factory()->create([
        'activity_id' => $activity->id,
        'rarity' => RunCard::RARITY_LEGENDARIS,
    ]);

    expect(app(UnlockEngine::class)->grantEligible($user))
        ->toContain('accessory.headband_legendaris');
});

it('grants headband_epik after three Epik run cards', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 3) as $_) {
        $activity = Activity::factory()->for($user)->create();
        RunCard::factory()->create([
            'activity_id' => $activity->id,
            'rarity' => RunCard::RARITY_EPIK,
        ]);
    }

    expect(app(UnlockEngine::class)->grantEligible($user))
        ->toContain('accessory.headband_epik');
});

it('grants weekly_streak_4 after four weekly snapshots with runs', function (): void {
    $user = User::factory()->create();
    foreach (range(1, 4) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 3,
        ]);
    }

    expect(app(UnlockEngine::class)->grantEligible($user))
        ->toContain('accessory.weekly_streak_4');
});

it('flashes a toast payload to the session when a session is active', function (): void {
    Session::start();
    config()->set('temari_unlocks', [
        'accessory' => [
            'medal_first_pr' => ['name' => 'Medali Custom', 'icon' => 'mdi:trophy'],
        ],
    ]);

    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    app(UnlockEngine::class)->grantEligible($user);

    $flashed = Session::get('unlock');
    expect($flashed)->toBeArray()
        ->and($flashed['unlock_key'])->toBe('accessory.medal_first_pr')
        ->and($flashed['name'])->toBe('Medali Custom')
        ->and($flashed['icon'])->toBe('mdi:trophy');
});

it('skips the flash when the unlock has no config entry', function (): void {
    Session::start();
    config()->set('temari_unlocks', []);

    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    app(UnlockEngine::class)->grantEligible($user);

    expect(Session::get('unlock'))->toBeNull();
});

it('falls back to the key + default icon when the config entry omits name and icon', function (): void {
    Session::start();
    config()->set('temari_unlocks', [
        'accessory' => [
            'medal_first_pr' => ['description' => 'x'],
        ],
    ]);

    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->create();

    app(UnlockEngine::class)->grantEligible($user);

    expect(Session::get('unlock'))->toBe([
        'unlock_key' => 'accessory.medal_first_pr',
        'name' => 'accessory.medal_first_pr',
        'icon' => 'mdi:medal',
    ]);
});
