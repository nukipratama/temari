<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Services\Run\Story\Card\CardAspect;
use App\Services\Run\Story\Card\CardOptions;
use App\Services\Run\Story\Card\CardStyle;
use App\Services\Run\Story\RunCardImageRenderer;
use Illuminate\Support\Carbon;

/** The 8-byte PNG file signature. */
const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

/** A short encoded loop, enough for the projector to draw a real trace. */
const LOOP_POLYLINE = '_p~iF~ps|U_ulLnnqC_mqNvxq`@';

/**
 * The renderer only reads `$card->activity->detail` (loadMissing() is a no-op
 * once the relation is set), so a persisted chain isn't needed. user_id is
 * pinned to a literal so the Activity factory doesn't fall through to its
 * `User::factory()` default, which persists a real User row even under make().
 *
 * @param  array<string, mixed>  $detailAttrs
 * @param  array<string, mixed>  $cardAttrs
 */
function makeRunCard(array $detailAttrs = [], array $cardAttrs = []): RunCard
{
    $detail = ActivityDetail::factory()->make(array_merge([
        'activity_id' => 1,
        'distance' => 5_280.0,
        'elapsed_time' => 1_938,
        'average_heartrate' => 142.0,
        'total_elevation_gain' => 18.0,
        'summary_polyline' => LOOP_POLYLINE,
        'location_name' => 'Senayan, Jakarta Pusat, Indonesia',
        'start_date_local' => Carbon::parse('2026-09-13 05:41:00'),
        'weather_temp_c' => 29,
        'weather_wind_speed_kmh' => 8,
        'workout_type' => 0,
        'stream_summary' => null,
    ], $detailAttrs));

    $activity = Activity::factory()->make(['id' => 1, 'user_id' => 1]);
    $activity->setRelation('detail', $detail);

    $card = RunCard::factory()->make(array_merge([
        'activity_id' => 1,
        'rarity' => 'common',
        'badges' => [],
        'pr_set' => false,
        'special_move' => 'Senayan Shuffle',
    ], $cardAttrs));
    $card->id = 418;
    $card->setRelation('activity', $activity);

    return $card;
}

/**
 * The five run forms, as the fixtures that resolve to them.
 *
 * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
 */
function runFormFixtures(): array
{
    return [
        'easy' => [[], []],
        'long' => [['distance' => 18_400.0, 'elapsed_time' => 6_750], ['rarity' => 'uncommon', 'badges' => ['long_slow_distance']]],
        'race' => [['distance' => 10_000.0, 'elapsed_time' => 3_341, 'workout_type' => 1, 'name' => 'Jakarta City 10K'], ['rarity' => 'rare']],
        'pr' => [['distance' => 5_020.0, 'elapsed_time' => 1_488], ['rarity' => 'epic', 'pr_set' => true, 'badges' => ['speedster', 'negative_split']]],
        'nogps' => [['summary_polyline' => null, 'location_name' => null, 'weather_temp_c' => null], []],
    ];
}

function renderer(): RunCardImageRenderer
{
    return app(RunCardImageRenderer::class);
}

it('draws every style at every form and aspect as a well-formed SVG of the right size', function (): void {
    foreach (runFormFixtures() as $form => [$detailAttrs, $cardAttrs]) {
        $card = makeRunCard($detailAttrs, $cardAttrs);

        foreach (CardStyle::cases() as $style) {
            foreach (CardAspect::cases() as $aspect) {
                $svg = renderer()->buildSvg($card, $style, $aspect);
                $where = "{$style->value}/{$form}/{$aspect->value}";

                expect(simplexml_load_string($svg))->not->toBeFalse("{$where} is not parseable SVG")
                    ->and($svg)->toContain('width="1080" height="'.$aspect->height().'"')
                    // Every card carries the wordmark, whatever the style.
                    ->and($svg)->toContain('>temari<');
            }
        }
    }
});

it('carries distance, time, pace, date, place and the wordmark on every style', function (): void {
    $card = makeRunCard();

    foreach (CardStyle::cases() as $style) {
        $svg = renderer()->buildSvg($card, $style, CardAspect::Story);

        expect($svg)->toContain('5.28')                        // distance, 2 decimals
            ->and($svg)->toContain('32:18')                     // elapsed time
            ->and($svg)->toContain('6:07')                      // pace
            ->and($svg)->toContain('SENAYAN')                   // place
            ->and($svg)->toContain('font-family="Fraunces"');   // the wordmark
    }
});

it('stamps the date in the app format, and never Temari narration', function (): void {
    $svg = renderer()->buildSvg(makeRunCard(), CardStyle::Broadsheet, CardAspect::Story);

    // formatShortDateId writes "13 sep 2026"; the card sets the same reading in
    // mono caps. The short stamp is the numeric form of the same date.
    expect(mb_strtolower($svg))->toContain('13 sep 2026')
        ->and($svg)->toContain('13.09.26')
        ->and($svg)->not->toContain('Senayan Shuffle');
});

it('holds a story card inside the story app safe zone', function (): void {
    foreach (CardStyle::cases() as $style) {
        $svg = renderer()->buildSvg(makeRunCard(), $style, CardAspect::Story);
        preg_match_all('/ y="(-?[\d.]+)"/', $svg, $found);
        $ys = array_map(floatval(...), array_filter($found[1], fn (string $y): bool => (float) $y > 0));

        expect(min($ys))->toBeGreaterThanOrEqual((float) CardAspect::SAFE_TOP)
            ->and(max($ys))->toBeLessThanOrEqual((float) CardAspect::SAFE_BOTTOM);
    }
});

it('strips the emoji emblem off a badge label', function (): void {
    $card = makeRunCard([], ['badges' => ['heat_tamer']]);
    $svg = renderer()->buildSvg($card, CardStyle::Ticket, CardAspect::Story);

    expect($svg)->toContain('HEAT TAMER')
        ->and($svg)->not->toContain('🔥');
});

it('draws the route placeholder rather than a blank panel without GPS', function (): void {
    $card = makeRunCard(['summary_polyline' => null]);

    expect(renderer()->buildSvg($card, CardStyle::Broadsheet, CardAspect::Story))->toContain('NO GPS')
        ->and(renderer()->buildSvg($card, CardStyle::Ticket, CardAspect::Story))->toContain('NO SIGNAL')
        ->and(renderer()->buildSvg($card, CardStyle::TopoPlate, CardAspect::Story))->toContain('UNSURVEYED');
});

it('treats a polyline that decodes to one point as no route at all', function (): void {
    // Strava ships these for a very short or paused activity; every style has
    // to fall back rather than draw an empty route area.
    $card = makeRunCard(['summary_polyline' => '_p~iF~ps|U']);

    expect(renderer()->buildSvg($card, CardStyle::Broadsheet, CardAspect::Story))->toContain('NO GPS')
        ->and(renderer()->buildSvg($card, CardStyle::Ticket, CardAspect::Story))->toContain('NO SIGNAL')
        ->and(renderer()->buildSvg($card, CardStyle::TopoPlate, CardAspect::Story))->toContain('UNSURVEYED');
});

it('promotes the route and the elevation cell on a long run', function (): void {
    [$detailAttrs, $cardAttrs] = runFormFixtures()['long'];
    $svg = renderer()->buildSvg(makeRunCard($detailAttrs, $cardAttrs), CardStyle::Broadsheet, CardAspect::Story);

    expect($svg)->toContain('LONG RUN')
        ->and($svg)->toContain('ELEV')
        ->and($svg)->not->toContain('AVG HR');
});

it('turns a race into its own composition on every style', function (): void {
    [$detailAttrs, $cardAttrs] = runFormFixtures()['race'];
    $card = makeRunCard($detailAttrs, $cardAttrs);

    expect(renderer()->buildSvg($card, CardStyle::Broadsheet, CardAspect::Story))
        ->toContain('JAKARTA CITY 10K')->toContain('BIB 0418')
        ->and(renderer()->buildSvg($card, CardStyle::Ticket, CardAspect::Story))
        ->toContain('FINISH')->toContain('10K')
        ->and(renderer()->buildSvg($card, CardStyle::TopoPlate, CardAspect::Story))
        ->toContain('FINISH');
});

it('drops a toggled-off fact from the card', function (): void {
    $card = makeRunCard([], ['badges' => ['heat_tamer']]);
    $options = new CardOptions(heartRate: false, elevation: false, weather: false, badges: false);
    $svg = renderer()->buildSvg($card, CardStyle::TopoPlate, CardAspect::Story, $options);

    expect($svg)->not->toContain('AVG HR')
        ->and($svg)->not->toContain('ELEV')
        ->and($svg)->not->toContain('HEAT TAMER')
        ->and($svg)->not->toContain('29°C');
});

it('rasterises to PNG bytes at the aspect\'s exact pixel size', function (): void {
    $png = renderer()->render(makeRunCard(), CardStyle::Broadsheet, CardAspect::Feed);
    $size = getimagesizefromstring($png);

    expect(str_starts_with($png, PNG_MAGIC))->toBeTrue()
        ->and($size[0])->toBe(1080)
        ->and($size[1])->toBe(1080);
});

it('defaults to the broadsheet story card, the shape the Telegram photo takes', function (): void {
    $card = makeRunCard();

    expect(renderer()->buildSvg($card))->toBe(
        renderer()->buildSvg($card, CardStyle::Broadsheet, CardAspect::Story),
    );
});
