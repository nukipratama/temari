<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\PersonalRecord;
use App\Models\RunCard;
use App\Models\User;
use App\Models\UserUnlock;
use App\Models\WeeklySnapshot;
use App\Services\Gamification\GoalResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->resolver = app(GoalResolver::class);
});

/**
 * @param  array<string, int|float>  $detail
 */
function makeActivity(User $user, array $detail = []): Activity
{
    $activity = Activity::factory()->for($user)->create();
    $activity->detail()->create([
        'distance' => $detail['distance'] ?? 5000.0,
        'moving_time' => $detail['moving_time'] ?? 1800,
        'average_speed' => $detail['average_speed'] ?? 2.8,
    ]);

    return $activity;
}

/**
 * @param  list<string>  $badges
 */
function makeCard(User $user, Rarity $rarity, array $badges = []): RunCard
{
    $activity = Activity::factory()->for($user)->create();

    return RunCard::factory()->for($activity)->create([
        'rarity' => $rarity,
        'badges' => $badges,
    ]);
}

/**
 * @return array<string, array{id: string, title: string, description: string, slot: string, rarity: string, current: int|float, target: int|float, unit: string, is_completed: bool}>
 */
function goalsById(GoalResolver $resolver, User $user): array
{
    $goals = $resolver->forUser($user);

    return collect($goals)->keyBy('id')->all();
}

it('returns the full goal catalog at zero progress for a fresh user', function (): void {
    $user = User::factory()->create();

    $goals = $this->resolver->forUser($user);

    // 4 medal + 4 ikat_kepala + 4 kaus + 4 celana + 4 sepatu + 4 aura.
    expect($goals)->toHaveCount(24);

    foreach ($goals as $goal) {
        expect($goal['is_completed'])->toBeFalse()
            ->and($goal['target'])->toBeGreaterThan(0)
            ->and($goal['current'])->toBeLessThanOrEqual($goal['target']);
    }

    $byId = collect($goals)->keyBy('id');
    expect($byId['accessory.medal_pertama']['current'])->toBe(0)
        ->and($byId['accessory.sepatu_cepat']['current'])->toBe(0);
});

it('counts PRs toward the medal goals and caps current at target', function (): void {
    $user = User::factory()->create();
    PersonalRecord::factory()->for($user)->count(6)->sequence(
        ['category' => '1km'],
        ['category' => '5km'],
        ['category' => '10km'],
        ['category' => '15km'],
        ['category' => 'half_marathon'],
        ['category' => 'marathon'],
    )->create();

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.medal_pertama']['current'])->toBe(1)   // capped at target 1
        ->and($byId['accessory.medal_emas']['current'])->toBe(5)   // capped at target 5
        ->and($byId['accessory.medal_perak']['current'])->toBe(6)  // below target 10
        ->and($byId['accessory.medal_platina']['current'])->toBe(6); // below target 20
});

it('marks a goal completed only when the unlock_key is present, independent of progress', function (): void {
    $user = User::factory()->create();
    // No PRs at all, but the medal_pertama unlock is recorded.
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_pertama']);

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.medal_pertama']['is_completed'])->toBeTrue()
        ->and($byId['accessory.medal_pertama']['current'])->toBe(0)
        ->and($byId['accessory.medal_emas']['is_completed'])->toBeFalse();
});

it('counts rarity cards toward ikat_kepala goals', function (): void {
    $user = User::factory()->create();
    makeCard($user, Rarity::Uncommon);
    makeCard($user, Rarity::Uncommon);
    makeCard($user, Rarity::Rare);
    makeCard($user, Rarity::Legendary);

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.ikat_kepala_berkesan']['current'])->toBe(2) // 2 uncommon, target 3
        ->and($byId['accessory.ikat_kepala_langka']['current'])->toBe(1) // 1 rare, target 3
        ->and($byId['accessory.ikat_kepala_legendaris']['current'])->toBe(1); // 1 legendary, target 1
});

it('tracks consecutive-week streak for aura_pemanasan', function (): void {
    $user = User::factory()->create();
    // 3 consecutive weeks ending on adjacent Sundays.
    $base = Carbon::parse('2026-05-31');
    foreach ([0, 1, 2] as $w) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => $base->copy()->subWeeks($w)->toDateString(),
            'runs' => 2,
        ]);
    }

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.aura_pemanasan']['current'])->toBe(2); // min(streak, 2)
});

it('tracks accumulated distance toward sepatu km goals', function (): void {
    $user = User::factory()->create();
    // 60 km total across two runs.
    makeActivity($user, ['distance' => 40000.0]);
    makeActivity($user, ['distance' => 20000.0]);

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.sepatu_legendaris']['current'])->toBe(60.0); // /1000
});

it('counts activities toward kaus_pemula, sepatu_basic and kaus_legendaris', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->analyzed()->count(10)->create();

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.kaus_pemula']['current'])->toBe(1)      // capped at 1
        ->and($byId['accessory.sepatu_basic']['current'])->toBe(10) // exactly target
        ->and($byId['accessory.kaus_legendaris']['current'])->toBe(10); // below target 50
});

it('counts badge-bearing cards toward kaus_pagi, kaus_hujan and aura goals', function (): void {
    $user = User::factory()->create();
    makeCard($user, Rarity::Common, [Badge::AnakPagi->value]);
    makeCard($user, Rarity::Common, [Badge::PejuangHujan->value]);
    makeCard($user, Rarity::Common, [Badge::HariPanas->value]);
    makeCard($user, Rarity::Common, [Badge::Z2Master->value]);

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.kaus_pagi']['current'])->toBe(1)
        ->and($byId['accessory.kaus_hujan']['current'])->toBe(1)
        ->and($byId['accessory.aura_gerah']['current'])->toBe(1)
        ->and($byId['accessory.aura_tenang']['current'])->toBe(1);
});

it('counts 5k/10k/half-marathon distance runs toward the celana goals', function (): void {
    $user = User::factory()->create();
    makeActivity($user, ['distance' => 5000.0]);   // 5k+ only
    makeActivity($user, ['distance' => 10000.0]);  // 5k+ and 10k+
    makeActivity($user, ['distance' => 21097.5]);  // 5k+, 10k+, half

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.celana_ringan']['current'])->toBe(1)  // 5k+, capped 1
        ->and($byId['accessory.celana_jarak']['current'])->toBe(1)  // 10k+, capped 1
        ->and($byId['accessory.celana_maraton']['current'])->toBe(1); // half, capped 1
});

it('does not count a run just under the half-marathon distance', function (): void {
    $user = User::factory()->create();
    // 21,097.4 m is one decimetre short of the 21,097.5 HM constant.
    makeActivity($user, ['distance' => 21097.4]);

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.celana_maraton']['current'])->toBe(0);
});

it('counts a sub-5:30/km run toward sepatu_cepat but not a 5:33/km run', function (): void {
    $user = User::factory()->create();
    // 1000/330 = 5:30/km exactly (qualifies, >=). 3.0 m/s = 5:33/km (does not).
    makeActivity($user, ['average_speed' => 1000 / 330]); // exactly the threshold
    makeActivity($user, ['average_speed' => 3.0]);        // 5:33/km, too slow

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.sepatu_cepat']['current'])->toBe(1);
});

it('counts legendary cards toward aura_jagoan', function (): void {
    $user = User::factory()->create();
    makeCard($user, Rarity::Legendary);
    makeCard($user, Rarity::Legendary);

    $byId = goalsById($this->resolver, $user);

    expect($byId['accessory.aura_jagoan']['current'])->toBe(2); // target 3
});

it('completedCount counts only completed goals', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_pertama']);
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.kaus_pemula']);

    $goals = $this->resolver->forUser($user);

    expect($this->resolver->completedCount($goals))->toBe(2);
});

it('closestToCompletion ranks in-progress goals by pct and pushes completed ones last', function (): void {
    $user = User::factory()->create();
    // 5 PRs feed medal_emas (5/5 = 100%) but it is NOT unlocked, so it leads.
    // The completed medal_pertama is pushed to the back.
    UserUnlock::factory()->for($user)->create(['unlock_key' => 'accessory.medal_pertama']);
    PersonalRecord::factory()->for($user)->count(5)->sequence(
        ['category' => '1km'],
        ['category' => '5km'],
        ['category' => '10km'],
        ['category' => '15km'],
        ['category' => 'half_marathon'],
    )->create();

    $closest = $this->resolver->closestToCompletion($user, 28);

    // Highest-pct in-progress goal first.
    expect($closest[0]['is_completed'])->toBeFalse()
        ->and($closest[0]['id'])->toBe('accessory.medal_emas')
        // The only completed goal is sorted to the very end.
        ->and($closest[array_key_last($closest)]['id'])->toBe('accessory.medal_pertama')
        ->and($closest[array_key_last($closest)]['is_completed'])->toBeTrue();
});

it('reuses precomputed goals in closestToCompletion when provided', function (): void {
    $user = User::factory()->create();
    $goals = $this->resolver->forUser($user);

    $closest = $this->resolver->closestToCompletion($user, 2, $goals);

    expect($closest)->toHaveCount(2);
});

it('carries the catalog rarity onto each goal', function (): void {
    $user = User::factory()->create();

    $byId = goalsById($this->resolver, $user);

    // From config/temari_unlocks.php.
    expect($byId['accessory.medal_pertama']['rarity'])->toBe('common')
        ->and($byId['accessory.medal_emas']['rarity'])->toBe('uncommon');
});
