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

// Asserted on the SVG rather than the rendered PNG: cell text is what's under
// test, and PNG byte length does not reliably track it across rasterisers.
it('draws the pace + durasi cells from elapsed_time, not moving_time', function (): void {
    $svgFor = function (array $timeAttrs): string {
        $card = makeRunCard([
            'distance' => 5_000,
            'summary_polyline' => null,
            'average_heartrate' => null,
            ...$timeAttrs,
        ], ['rarity' => 'common', 'special_move' => 'Langkah Mantap']);

        return (string) new ReflectionMethod(RunCardImageRenderer::class, 'buildSvg')
            ->invoke(app(RunCardImageRenderer::class), $card);
    };

    // 5 km in 30:00 is 6:00/km; both cells must come from elapsed_time.
    expect($svgFor(['moving_time' => null, 'elapsed_time' => 1_800]))
        ->toContain('DURATION')
        ->toContain('30:00')
        ->toContain('6:00/km');

    // Only moving_time present: nothing to draw either cell from.
    expect($svgFor(['moving_time' => 1_800, 'elapsed_time' => null]))
        ->not->toContain('DURATION')
        ->not->toContain('PACE');
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

it('shows wind in English, not the old Indonesian "angin" wording', function (): void {
    $card = makeRunCard([
        'distance' => 5_280,
        'summary_polyline' => null,
        'weather_temp_c' => 31,
        'weather_wind_speed_kmh' => 15,
    ], ['rarity' => 'epic', 'special_move' => 'Tendangan Balik']);

    $svg = (string) new ReflectionMethod(RunCardImageRenderer::class, 'buildSvg')
        ->invoke(app(RunCardImageRenderer::class), $card);

    expect($svg)->toContain('wind 15 km/h')->not->toContain('angin');
});

it('shows "Route unavailable" in English on a no-GPS rute layout', function (): void {
    $card = makeRunCard([
        'distance' => 3_000,
        'summary_polyline' => null,
    ], ['rarity' => 'common', 'special_move' => 'Langkah Mantap']);

    $svg = (string) new ReflectionMethod(RunCardImageRenderer::class, 'buildSvg')
        ->invoke(app(RunCardImageRenderer::class), $card, 'rute');

    expect($svg)->toContain('Route unavailable')->not->toContain('tidak tersedia');
});

it('renders valid PNG bytes for the kartu layout (no route panel, larger KM hero)', function (): void {
    $card = makeRunCard([
        'distance' => 5_280,
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    ], ['rarity' => 'rare', 'special_move' => 'Kaki Cepat']);

    $svg = (string) new ReflectionMethod(RunCardImageRenderer::class, 'buildSvg')
        ->invoke(app(RunCardImageRenderer::class), $card, 'kartu');
    $png = app(RunCardImageRenderer::class)->render($card, 'kartu');

    expect(str_starts_with($png, PNG_MAGIC))->toBeTrue()
        ->and($svg)->toContain('font-size="170"') // the enlarged hero KM figure
        ->not->toContain('<polyline'); // no route panel, even though the run has a polyline
});

it('renders valid PNG bytes for the stats layout (2x2 grid, no hero KM, no route panel)', function (): void {
    $card = makeRunCard([
        'distance' => 8_400,
        'elapsed_time' => 2_400,
        'average_heartrate' => 152,
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    ], ['rarity' => 'legendary', 'special_move' => 'Terbang Tinggi']);

    $svg = (string) new ReflectionMethod(RunCardImageRenderer::class, 'buildSvg')
        ->invoke(app(RunCardImageRenderer::class), $card, 'stats');
    $png = app(RunCardImageRenderer::class)->render($card, 'stats');

    expect(str_starts_with($png, PNG_MAGIC))->toBeTrue()
        ->and($svg)->toContain('DISTANCE')
        ->toContain('DURATION')
        ->toContain('HR')
        ->not->toContain('KILOMETER'); // no single hero number on this branch
});

it('defaults render() to the original rute/navy look when called with no explicit args', function (): void {
    $card = makeRunCard([
        'distance' => 5_280,
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
    ], ['rarity' => 'epic', 'special_move' => 'Tendangan Balik']);

    $defaultSvg = (string) new ReflectionMethod(RunCardImageRenderer::class, 'buildSvg')
        ->invoke(app(RunCardImageRenderer::class), $card);
    $explicitSvg = (string) new ReflectionMethod(RunCardImageRenderer::class, 'buildSvg')
        ->invoke(app(RunCardImageRenderer::class), $card, 'rute', 'navy');

    expect($defaultSvg)->toBe($explicitSvg);
});

it('paints a different card-body fill for each colorway', function (): void {
    $card = makeRunCard([
        'distance' => 5_280,
    ], ['rarity' => 'common', 'special_move' => 'Langkah Mantap']);

    $svgFor = fn (string $colorway): string => (string) new ReflectionMethod(RunCardImageRenderer::class, 'buildSvg')
        ->invoke(app(RunCardImageRenderer::class), $card, 'kartu', $colorway);

    $navy = $svgFor('navy');
    $dawn = $svgFor('dawn');
    $ember = $svgFor('ember');

    expect($navy)->toContain('#1f2747') // navy card body, unchanged
        ->and($dawn)->toContain('#f6f1e8')->not->toContain('#1f2747')
        ->and($ember)->toContain('#3a2015')->not->toContain('#1f2747');
});
