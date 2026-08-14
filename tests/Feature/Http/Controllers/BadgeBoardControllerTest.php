<?php

declare(strict_types=1);

use App\Enums\Badge;
use App\Enums\DistanceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\RunCard;
use App\Models\Season;
use App\Models\User;
use App\Models\UserUnlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-10 08:00:00'));
afterEach(fn () => Carbon::setTestNow());

it('requires authentication', function (): void {
    $this->get('/badges')->assertRedirect('/login');
});

it('renders all 16 badge cases plus the rest-day entry', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/badges')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Collection/Badges')
            ->has('items', count(Badge::cases()) + 1));
});

it('shows a lifetime count for an earned badge', function (): void {
    $user = User::factory()->create();
    RunCard::factory()->for(Activity::factory()->for($user))->create([
        'badges' => [Badge::Speedster->value],
    ]);

    $response = $this->actingAs($user)->get('/badges')->assertSuccessful();
    $items = collect($response->viewData('page')['props']['items']);

    $speedster = $items->firstWhere('key', Badge::Speedster->value);
    expect($speedster['unlocked'])->toBeTrue()
        ->and($speedster['lifetime_count'])->toBe(1);
});

it('leaves an unearned badge locked with a zero count', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/badges')->assertSuccessful();
    $items = collect($response->viewData('page')['props']['items']);

    $item = $items->firstWhere('key', Badge::Speedster->value);
    expect($item['unlocked'])->toBeFalse()
        ->and($item['lifetime_count'])->toBe(0);
});

it('scopes the season count to the active season, distinct from the lifetime count', function (): void {
    $user = User::factory()->create();

    // A card from well before any season this user could have (created lazily
    // on this very request) — proves lifetime and "this season" diverge.
    $old = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($old)->create(['start_date_local' => '2025-01-01 07:00:00']);
    RunCard::factory()->for($old)->create(['badges' => [Badge::Speedster->value]]);

    $current = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($current)->create(['start_date_local' => '2026-08-10 07:00:00']);
    RunCard::factory()->for($current)->create(['badges' => [Badge::Speedster->value]]);

    $response = $this->actingAs($user)->get('/badges')->assertSuccessful();
    $items = collect($response->viewData('page')['props']['items']);
    $item = $items->firstWhere('key', Badge::Speedster->value);

    expect($item['lifetime_count'])->toBe(2)
        ->and($item['season_count'])->toBe(1);
});

it('grants the rest-honored unlock once the season threshold is reached, and reflects it on the board', function (): void {
    $user = User::factory()->create();
    // A season that's already been running for a few days, so the honored
    // rest days below (dated in the past) fall inside its range — a season
    // that starts today has no past days to have honored anything in yet.
    Season::factory()->for($user)->create([
        'starts_at' => Carbon::today()->subDays(5)->toDateString(),
        'ends_at' => Carbon::today()->addWeeks(11)->toDateString(),
    ]);

    foreach ([1, 2, 3] as $daysAgo) {
        PlannedSession::factory()->for($user)->create([
            'date' => Carbon::today()->subDays($daysAgo)->toDateString(),
            'phase' => PlanPhase::Base,
            'session_type' => SessionType::Rest,
            'distance_band' => DistanceBand::Rest,
            'pace_band' => null,
        ]);
    }

    $response = $this->actingAs($user)->get('/badges')->assertSuccessful();
    $items = collect($response->viewData('page')['props']['items']);
    $rest = $items->firstWhere('key', 'season.rest_honored');

    expect($rest['unlocked'])->toBeTrue()
        ->and($rest['season_count'])->toBe(3)
        ->and(UserUnlock::query()->where('user_id', $user->id)
            ->where('unlock_key', 'like', 'season.%.rest_honored_3')
            ->exists())->toBeTrue();
});

it('does not grant the rest-honored unlock when a day merely has no planned session at all', function (): void {
    $user = User::factory()->create();
    // No PlannedSession rows at all — a day with nothing planned is not the
    // same as a Rest day that was honored.

    $this->actingAs($user)->get('/badges')->assertSuccessful();

    expect(UserUnlock::query()->where('user_id', $user->id)
        ->where('unlock_key', 'like', 'season.%.rest_honored_%')
        ->exists())->toBeFalse();
});
