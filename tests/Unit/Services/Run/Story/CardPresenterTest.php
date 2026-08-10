<?php

declare(strict_types=1);

use App\Enums\Rarity;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\RunCard;
use App\Models\StoryLine;
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

it('counts every rarity, zero-filling the ones the user has none of', function (): void {
    $user = User::factory()->create();
    presenterCard($user, Rarity::Common);
    presenterCard($user, Rarity::Common);
    presenterCard($user, Rarity::Epic);

    expect(app(CardPresenter::class)->rarityCounts($user->id))->toBe([
        'common' => 2,
        'uncommon' => 0,
        'rare' => 0,
        'epic' => 1,
        'legendary' => 0,
    ]);
});

it('excludes other users from the rarity counts', function (): void {
    $user = User::factory()->create();
    presenterCard($user, Rarity::Rare);
    presenterCard(User::factory()->create(), Rarity::Rare);

    expect(app(CardPresenter::class)->rarityCounts($user->id)['rare'])->toBe(1);
});

it('numbers editions per rarity chronologically by id', function (): void {
    $user = User::factory()->create();
    $firstCommon = presenterCard($user, Rarity::Common);
    $onlyEpic = presenterCard($user, Rarity::Epic);
    $secondCommon = presenterCard($user, Rarity::Common);

    expect(app(CardPresenter::class)->editionIndexMap($user->id))->toEqual([
        $firstCommon->id => 1,
        $onlyEpic->id => 1,
        $secondCommon->id => 2,
    ]);
});

it('reads the edition out of a prebuilt index map', function (): void {
    $user = User::factory()->create();
    $card = presenterCard($user, Rarity::Epic);
    $presenter = app(CardPresenter::class);

    expect($presenter->editionFromMap($card, [$card->id => 3], ['epic' => 7]))
        ->toBe(['index' => 3, 'total' => 7]);
});

it('falls back to 1 of 1 when the map has no entry for the card', function (): void {
    $user = User::factory()->create();
    $card = presenterCard($user, Rarity::Legendary);

    expect(app(CardPresenter::class)->editionFromMap($card, [], []))
        ->toBe(['index' => 1, 'total' => 1]);
});

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
    $card->update(['share_image_path' => 'kartu/secret.png']);

    expect(app(CardPresenter::class)->base($card))->toBe([
        'id' => $card->id,
        'activity_id' => $card->activity_id,
        'rarity' => 'epic',
        'special_move' => $card->special_move,
        'badges' => $card->badges,
    ]);
});

it('prefers the post-run story line mood', function (): void {
    $user = User::factory()->create();
    $card = presenterCard($user, Rarity::Common);
    StoryLine::factory()->create([
        'user_id' => $user->id,
        'activity_id' => $card->activity_id,
        'kind' => StoryLine::KIND_POST_RUN,
        'mood' => 'wobbly',
    ]);

    expect(app(CardPresenter::class)->mood($card->fresh()))->toBe('wobbly');
});

it('falls back to the derived mood when there is no post-run story line', function (): void {
    $user = User::factory()->create();
    $card = presenterCard($user, Rarity::Common);

    expect(app(CardPresenter::class)->mood($card))->toBeString();
});

it('shapes the card flavor analysis payload', function (): void {
    $user = User::factory()->create();
    $card = presenterCard($user, Rarity::Rare);
    Analysis::factory()->done('Larimu ringan.')->create([
        'subject_type' => RunCard::class,
        'subject_id' => $card->id,
        'analysis_type' => AnalysisType::CardFlavor,
        'discriminator' => null,
    ]);

    expect(app(CardPresenter::class)->flavorAnalysis($card))
        ->toMatchArray([
            'content' => 'Larimu ringan.',
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
