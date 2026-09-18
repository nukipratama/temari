<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Models\User;
use App\Services\Run\Story\Card\CardAspect;
use App\Services\Run\Story\Card\CardOptions;
use App\Services\Run\Story\Card\CardStyle;
use App\Services\Run\Story\RunCardImageRenderer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/** @return array{0: User, 1: Activity, 2: RunCard} */
function runWithCard(?User $owner = null): array
{
    $user = $owner ?? User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_284.0,
        'elapsed_time' => 1_938,
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    ]);
    $card = RunCard::factory()->for($activity)->create(['rarity' => 'epic']);

    return [$user, $activity, $card];
}

it('requires authentication', function (): void {
    [, $activity] = runWithCard();

    $this->get("/activities/{$activity->id}/card.png")->assertRedirect('/login');
});

it('serves the PNG for the athlete\'s own run', function (): void {
    [$user, $activity] = runWithCard();

    $response = $this->actingAs($user)->get("/activities/{$activity->id}/card.png");

    $response->assertSuccessful()
        ->assertHeader('Content-Type', 'image/png');
    expect($response->getContent())->toStartWith("\x89PNG");
});

it('hides another athlete\'s run behind a 404', function (): void {
    [, $activity] = runWithCard();
    $intruder = User::factory()->create();

    $this->actingAs($intruder)->get("/activities/{$activity->id}/card.png")->assertNotFound();
});

it('404s a run that has no card yet', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create();

    $this->actingAs($user)->get("/activities/{$activity->id}/card.png")->assertNotFound();
});

it('renders the style and aspect the query asks for', function (): void {
    [$user, $activity, $card] = runWithCard();

    $this->mock(RunCardImageRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->withArgs(fn (RunCard $rendered, CardStyle $style, CardAspect $aspect, CardOptions $options): bool => $rendered->is($card)
            && $style === CardStyle::TopoPlate
            && $aspect === CardAspect::Feed
            && $options->heartRate === false
            && $options->badges === true)
        ->andReturn('png-bytes');

    $this->actingAs($user)
        ->get("/activities/{$activity->id}/card.png?style=topo&aspect=feed&hr=false")
        ->assertSuccessful();
});

it('accepts the design round\'s a/b/c shorthand for a style', function (): void {
    [$user, $activity] = runWithCard();

    $this->mock(RunCardImageRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->withArgs(fn (RunCard $card, CardStyle $style): bool => $style === CardStyle::Ticket)
        ->andReturn('png-bytes');

    $this->actingAs($user)->get("/activities/{$activity->id}/card.png?style=b")->assertSuccessful();
});

it('falls back to the default print rather than erroring on a nonsense style', function (): void {
    [$user, $activity] = runWithCard();

    $this->mock(RunCardImageRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->withArgs(fn (RunCard $card, CardStyle $style, CardAspect $aspect): bool => $style === CardStyle::Broadsheet
            && $aspect === CardAspect::Story)
        ->andReturn('png-bytes');

    $this->actingAs($user)
        ->get("/activities/{$activity->id}/card.png?style=origami&aspect=panorama")
        ->assertSuccessful();
});

it('rasterises a tuple once and replays it for the next request', function (): void {
    [$user, $activity] = runWithCard();

    $this->mock(RunCardImageRenderer::class)
        ->shouldReceive('render')
        ->once()
        ->andReturn('png-bytes');

    $url = "/activities/{$activity->id}/card.png?style=ticket&aspect=story";
    $this->actingAs($user)->get($url)->assertSuccessful();
    $this->actingAs($user)->get($url)->assertSuccessful();
});

it('gives every toggle combination its own cache entry', function (): void {
    [$user, $activity] = runWithCard();

    $this->mock(RunCardImageRenderer::class)
        ->shouldReceive('render')
        ->twice()
        ->andReturn('png-bytes');

    $base = "/activities/{$activity->id}/card.png?style=ticket";
    $this->actingAs($user)->get("{$base}&elevation=true")->assertSuccessful();
    $this->actingAs($user)->get("{$base}&elevation=false")->assertSuccessful();
});

it('re-renders once the card itself changes', function (): void {
    [$user, $activity, $card] = runWithCard();

    $this->mock(RunCardImageRenderer::class)
        ->shouldReceive('render')
        ->twice()
        ->andReturn('png-bytes');

    $url = "/activities/{$activity->id}/card.png";
    $this->actingAs($user)->get($url)->assertSuccessful();

    // A rebuilt card lands a later updated_at, which is part of the key.
    $this->travel(2)->minutes();
    $card->forceFill(['rarity' => 'legendary'])->save();
    $this->actingAs($user)->get($url)->assertSuccessful();
});

it('keeps one athlete\'s card out of another\'s cache entry', function (): void {
    [$user, $activity, $card] = runWithCard();
    [$otherUser, $otherActivity, $otherCard] = runWithCard();

    Cache::flush();
    $this->mock(RunCardImageRenderer::class)
        ->shouldReceive('render')
        ->twice()
        ->andReturn('png-bytes');

    expect($card->id)->not->toBe($otherCard->id);
    $this->actingAs($user)->get("/activities/{$activity->id}/card.png")->assertSuccessful();
    $this->actingAs($otherUser)->get("/activities/{$otherActivity->id}/card.png")->assertSuccessful();
});
