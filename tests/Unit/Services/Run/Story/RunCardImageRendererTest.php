<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Services\Run\Story\RunCardImageRenderer;

/** The 8-byte PNG file signature. */
const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

function renderCard(RunCard $card): string
{
    return app(RunCardImageRenderer::class)->render($card);
}

/**
 * render() only reads $card->activity->detail (loadMissing() is a no-op once
 * the relation is already set), so a persisted chain isn't needed.
 *
 * user_id is pinned to a literal so the Activity factory doesn't fall through
 * to its `User::factory()` default, which persists a real User row even
 * under ->make() (nested belongsTo factory attributes are always create()'d).
 *
 * @param  array<string, mixed>  $detailAttrs
 * @param  array<string, mixed>  $cardAttrs
 */
function makeRunCard(array $detailAttrs, array $cardAttrs): RunCard
{
    $detail = ActivityDetail::factory()->make(array_merge(['activity_id' => 1], $detailAttrs));
    $activity = Activity::factory()->make(['id' => 1, 'user_id' => 1]);
    $activity->setRelation('detail', $detail);

    $card = RunCard::factory()->make(array_merge(['activity_id' => 1], $cardAttrs));
    $card->setRelation('activity', $activity);

    return $card;
}

it('renders valid PNG bytes for a card with a route polyline', function (): void {
    $card = makeRunCard([
        'distance' => 5_280,
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
        'location_name' => 'Yogyakarta',
    ], ['rarity' => 'epic', 'special_move' => 'Tendangan Balik']);

    $png = renderCard($card);

    expect(str_starts_with($png, PNG_MAGIC))->toBeTrue()
        ->and(strlen($png))->toBeGreaterThan(1000);
});

it('renders valid PNG bytes for a no-GPS card (fallback layout)', function (): void {
    $card = makeRunCard([
        'distance' => 3_000,
        'summary_polyline' => null,
    ], ['rarity' => 'common', 'special_move' => 'Langkah Mantap']);

    $png = renderCard($card);

    expect(str_starts_with($png, PNG_MAGIC))->toBeTrue();
});

it('renders a longer PNG when the footer line gains a weather + wind reading', function (): void {
    $card = makeRunCard([
        'distance' => 5_280,
        'summary_polyline' => null,
        'location_name' => 'Yogyakarta',
        'weather_temp_c' => 31,
        'weather_wind_speed_kmh' => 15,
    ], ['rarity' => 'epic', 'special_move' => 'Tendangan Balik']);

    $png = renderCard($card);

    expect(str_starts_with($png, PNG_MAGIC))->toBeTrue()
        ->and(strlen($png))->toBeGreaterThan(1000);
});

it('omits the weather footer segment gracefully when temp is absent', function (): void {
    $card = makeRunCard([
        'distance' => 5_280,
        'summary_polyline' => null,
        'location_name' => 'Yogyakarta',
        'weather_temp_c' => null,
        'weather_wind_speed_kmh' => 15,
    ], ['rarity' => 'epic', 'special_move' => 'Tendangan Balik']);

    $png = renderCard($card);

    expect(str_starts_with($png, PNG_MAGIC))->toBeTrue();
});
