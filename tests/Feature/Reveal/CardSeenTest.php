<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;

uses(RefreshDatabase::class);

it('clears pending_reveal_card_id when the user posts seen for the flagged card', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    $card = RunCard::factory()->for($activity)->create(['rarity' => 'epic']);

    $user->forceFill(['pending_reveal_card_id' => $card->id])->save();

    $this->actingAs($user)
        ->postJson("/api/kartu/{$card->id}/seen")
        ->assertSuccessful()
        ->assertJson(['seen' => true]);

    expect($user->fresh()->pending_reveal_card_id)->toBeNull();
});

it('no-ops when posting seen for a card that is not the flagged reveal', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    $cardA = RunCard::factory()->for($activity)->create();

    $otherActivity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($otherActivity)->create();
    $cardB = RunCard::factory()->for($otherActivity)->create();

    $user->forceFill(['pending_reveal_card_id' => $cardA->id])->save();

    $this->actingAs($user)->postJson("/api/kartu/{$cardB->id}/seen")->assertSuccessful();

    expect($user->fresh()->pending_reveal_card_id)->toBe($cardA->id);
});

it('forbids posting seen for another user\'s card', function (): void {
    $owner = User::factory()->create();
    $activity = Activity::factory()->for($owner)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    $card = RunCard::factory()->for($activity)->create();

    $intruder = User::factory()->create();
    $this->actingAs($intruder)->postJson("/api/kartu/{$card->id}/seen")->assertForbidden();
});

it('shares pendingReveal as null when no flag is set', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pendingReveal', null));
});

it('shares pendingReveal as null when the flagged card was deleted', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();
    $card = RunCard::factory()->for($activity)->create();
    $user->forceFill(['pending_reveal_card_id' => $card->id])->save();
    $card->delete();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pendingReveal', null));
});

it('shares pendingReveal payload when a card is flagged', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create(['name' => '10K race-pace']);
    $card = RunCard::factory()->for($activity)->create([
        'rarity' => 'epic',
        'special_move' => 'Pembalik Keadaan',
    ]);

    $user->forceFill(['pending_reveal_card_id' => $card->id])->save();

    $this->actingAs($user)->get('/')
        ->assertSuccessful()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('pendingReveal.card_id', $card->id)
            ->where('pendingReveal.rarity', 'epic')
            ->where('pendingReveal.special_move', 'Pembalik Keadaan')
            ->where('pendingReveal.detail_name', '10K race-pace'));
});
