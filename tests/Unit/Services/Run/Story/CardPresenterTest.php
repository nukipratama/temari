<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\User;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\Run\Story\CardPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function presenterCard(User $user, Rarity $rarity): RunCard
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    return RunCard::factory()->for($activity)->create(['rarity' => $rarity]);
}

it('resolves a single card edition with one aggregate pass', function (): void {
    $user = User::factory()->create();
    $first = presenterCard($user, Rarity::Uncommon);
    presenterCard($user, Rarity::Uncommon);
    presenterCard($user, Rarity::Epic);
    $presenter = app(CardPresenter::class);

    expect($presenter->edition($first, $user->id))->toBe(['index' => 1, 'total' => 2]);
});

it('scopes a single card edition to the owner', function (): void {
    $user = User::factory()->create();
    presenterCard(User::factory()->create(), Rarity::Rare);
    $card = presenterCard($user, Rarity::Rare);

    expect(app(CardPresenter::class)->edition($card, $user->id))->toBe(['index' => 1, 'total' => 1]);
});

it('whitelists the card columns, never internal ones', function (): void {
    $user = User::factory()->create();
    $card = presenterCard($user, Rarity::Epic);
    $card->update(['share_image_path' => 'card/secret.png']);

    expect(app(CardPresenter::class)->base($card))->toBe([
        'id' => $card->id,
        'activity_id' => $card->activity_id,
        'rarity' => 'epic',
        'special_move' => $card->special_move,
        'badges' => $card->badges,
    ]);
});

it('shapes the card flavor analysis payload', function (): void {
    $user = User::factory()->create();
    $card = presenterCard($user, Rarity::Rare);
    Analysis::factory()->done('Runmu ringan.')->create([
        'subject_type' => RunCard::class,
        'subject_id' => $card->id,
        'analysis_type' => AnalysisType::CardFlavor,
        'discriminator' => null,
    ]);

    expect(app(CardPresenter::class)->flavorAnalysis($card))
        ->toMatchArray([
            'content' => 'Runmu ringan.',
            'status' => AnalysisStatus::Done->value,
        ]);
});

it('returns a pending flavor payload when no analysis row exists', function (): void {
    $user = User::factory()->create();
    $card = presenterCard($user, Rarity::Rare);

    expect(app(CardPresenter::class)->flavorAnalysis($card))
        ->toMatchArray([
            'content' => null,
            'status' => AnalysisStatus::Pending->value,
        ]);
});
