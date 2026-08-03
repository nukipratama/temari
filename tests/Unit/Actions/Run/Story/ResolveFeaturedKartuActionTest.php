<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use App\Actions\Run\Story\ResolveFeaturedKartuAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

function resolverRunWithCard(User $user, Rarity $rarity, Carbon $when, ?float $distance = 5000.0): RunCard
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $when,
        'distance' => $distance,
    ]);

    return RunCard::factory()->for($activity)->create(['rarity' => $rarity]);
}

it('returns null when the user has no cards', function (): void {
    $user = User::factory()->create();

    expect(app(ResolveFeaturedKartuAction::class)($user))->toBeNull();
});

it('picks the highest rarity card over more recent lower-rarity ones', function (): void {
    $user = User::factory()->create();
    resolverRunWithCard($user, Rarity::Common, Carbon::today());
    $legendary = resolverRunWithCard($user, Rarity::Legendary, Carbon::today()->subDay());

    expect(app(ResolveFeaturedKartuAction::class)($user)?->id)->toBe($legendary->id);
});

it('breaks a rarity tie toward the most recent run', function (): void {
    $user = User::factory()->create();
    resolverRunWithCard($user, Rarity::Epic, Carbon::today()->subDays(3));
    $recentEpic = resolverRunWithCard($user, Rarity::Epic, Carbon::today()->subDay());

    expect(app(ResolveFeaturedKartuAction::class)($user)?->id)->toBe($recentEpic->id);
});

it('skips analyzed runs that have no card', function (): void {
    $user = User::factory()->create();
    $cardless = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($cardless)->create(['start_date_local' => Carbon::today()]);
    $carded = resolverRunWithCard($user, Rarity::Rare, Carbon::today()->subDay());

    expect(app(ResolveFeaturedKartuAction::class)($user)?->id)->toBe($carded->id);
});

it('only considers the last 8 runs by date', function (): void {
    $user = User::factory()->create();
    // An old legendary buried beyond the 8-run window must not win.
    resolverRunWithCard($user, Rarity::Legendary, Carbon::today()->subDays(30));
    for ($i = 0; $i < 8; $i++) {
        resolverRunWithCard($user, Rarity::Common, Carbon::today()->subDays($i));
    }

    expect(app(ResolveFeaturedKartuAction::class)($user)?->rarity)->toBe(Rarity::Common);
});
