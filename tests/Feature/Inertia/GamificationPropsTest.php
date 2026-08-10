<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\RunCard;
use App\Models\User;
use App\Models\UserUnlock;
use App\Services\Inertia\GamificationProps;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function gamificationPropsFor(?User $user): array
{
    return app(GamificationProps::class)->forUser($user);
}

it('keeps every prop a closure so a partial reload can skip it', function (): void {
    $props = gamificationPropsFor(User::factory()->create());

    foreach (['equippedAccessories', 'pendingReveal', 'activeRace'] as $key) {
        expect($props[$key])->toBeInstanceOf(Closure::class);
    }
});

it('reports what the mascot is wearing', function (): void {
    $user = User::factory()->create();
    UserUnlock::factory()->for($user)->equipped()->create(['unlock_key' => 'accessory.medal_gold']);

    expect((gamificationPropsFor($user)['equippedAccessories'])())
        ->toMatchArray(['medal' => 'accessory.medal_gold']);
});

it('gives a guest the empty accessory set and no reveal', function (): void {
    $props = gamificationPropsFor(null);

    expect(($props['pendingReveal'])())->toBeNull()
        ->and(($props['equippedAccessories'])())->toBe([
            'medal' => null,
            'headband' => null,
            'shirt' => null,
            'shorts' => null,
            'shoes' => null,
            'aura' => null,
        ]);
});

it('returns no pending reveal when the user has no card waiting', function (): void {
    $user = User::factory()->create(['pending_reveal_card_id' => null]);

    expect((gamificationPropsFor($user)['pendingReveal'])())->toBeNull();
});

it('shapes the pending reveal from the waiting card', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $card = RunCard::factory()->for($activity)->create();
    $user->update(['pending_reveal_card_id' => $card->id]);

    $reveal = (gamificationPropsFor($user->fresh())['pendingReveal'])();

    expect($reveal)->not->toBeNull()
        ->and($reveal['card_id'])->toBe($card->id)
        ->and($reveal['activity_id'])->toBe($activity->id)
        ->and($reveal['rarity'])->toBe($card->rarity->value)
        ->and($reveal['public_share_url'])->toContain((string) $activity->id);
});

it('refuses a pending reveal pointing at another user card', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $card = RunCard::factory()->for(Activity::factory()->for($other)->create())->create();
    $user->update(['pending_reveal_card_id' => $card->id]);

    expect((gamificationPropsFor($user->fresh())['pendingReveal'])())->toBeNull();
});
