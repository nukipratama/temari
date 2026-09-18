<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunCard;
use App\Services\Run\Story\Card\CardFacts;
use App\Services\Run\Story\Card\RunForm;
use Illuminate\Support\Carbon;

/**
 * @param  array<string, mixed>  $detailAttrs
 * @param  array<string, mixed>  $cardAttrs
 */
function factsFor(array $detailAttrs = [], array $cardAttrs = []): CardFacts
{
    $detail = ActivityDetail::factory()->make(array_merge([
        'activity_id' => 1,
        'distance' => 5_284.0,
        'elapsed_time' => 1_938,
        'average_heartrate' => 141.6,
        'total_elevation_gain' => 18.4,
        'summary_polyline' => '_p~iF~ps|U_ulLnnqC_mqNvxq`@',
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
    ], $cardAttrs));
    $card->id = 418;
    $card->setRelation('activity', $activity);

    return CardFacts::from($card);
}

it('reads distance at the app\'s two decimals, not the one-decimal copy precision', function (): void {
    expect(factsFor()->km)->toBe('5.28');
});

it('stamps the date the way the app writes it, cased up for a mono label', function (): void {
    $facts = factsFor();

    expect($facts->dateLong)->toBe('SUN 13 SEP 2026')
        ->and(mb_strtolower($facts->dateLong))->toContain('13 sep 2026')
        ->and($facts->dateShort)->toBe('13.09.26')
        ->and($facts->clock)->toBe('05:41');
});

it('resolves the run form, race first and easy last', function (): void {
    expect(factsFor()->form)->toBe(RunForm::Easy)
        ->and(factsFor(['distance' => 18_400.0])->form)->toBe(RunForm::Long)
        ->and(factsFor([], ['pr_set' => true])->form)->toBe(RunForm::Pr)
        ->and(factsFor(['workout_type' => 1])->form)->toBe(RunForm::Race)
        ->and(factsFor(['summary_polyline' => null])->form)->toBe(RunForm::NoGps)
        // A race keeps its own form even when it also set a PR and ran long.
        ->and(factsFor(['workout_type' => 1, 'distance' => 18_400.0], ['pr_set' => true])->form)
        ->toBe(RunForm::Race);
});

it('separates having a route from the run\'s form', function (): void {
    // A long run whose GPS dropped still composes as a long run, but has no trace.
    $facts = factsFor(['distance' => 18_400.0, 'summary_polyline' => null]);

    expect($facts->form)->toBe(RunForm::Long)
        ->and($facts->polyline)->toBeNull();
});

it('strips the emoji emblem off a badge name and caps the row at three', function (): void {
    $facts = factsFor([], ['badges' => ['heat_tamer', 'speedster', 'night_owl', 'climber']]);

    expect($facts->badges)->toBe(['Heat Tamer', 'Speedster', 'Night Owl']);
});

it('drops an unknown badge slug rather than printing it raw', function (): void {
    expect(factsFor([], ['badges' => ['not_a_badge', 'climber']])->badges)->toBe(['Climber']);
});

it('carries every optional fact, since the chips that hide them live in the browser', function (): void {
    $facts = factsFor([], ['badges' => ['climber']]);

    expect($facts->heartRate)->toBe('142')
        ->and($facts->elevation)->toBe('18')
        ->and($facts->weather)->toBe('29°C · wind 8 km/h')
        ->and($facts->badges)->toBe(['Climber']);
});

it('ships the whole print as one payload the client can draw without a round trip', function (): void {
    $payload = factsFor([], ['badges' => ['climber']])->toArray();

    expect($payload['form'])->toBe('easy')
        ->and($payload['rarity'])->toBe('common')
        ->and($payload['km'])->toBe('5.28')
        ->and($payload['time'])->toBe('32:18')
        ->and($payload['pace'])->toBe('6:07')
        ->and($payload['heart_rate'])->toBe('142')
        ->and($payload['elevation'])->toBe('18')
        ->and($payload['place_short'])->toBe('SENAYAN')
        ->and($payload['date_long'])->toBe('SUN 13 SEP 2026')
        ->and($payload['serial'])->toBe('TMR-0418')
        ->and($payload['badges'])->toBe(['Climber'])
        ->and($payload['polyline'])->not->toBeNull();
});

it('names a race by its title and its round distance', function (): void {
    $facts = factsFor(['workout_type' => 1, 'distance' => 10_010.0, 'name' => 'Jakarta City 10K']);

    expect($facts->raceName)->toBe('JAKARTA CITY 10K')
        ->and($facts->raceDistance)->toBe('10K')
        ->and(factsFor(['workout_type' => 1, 'distance' => 21_097.5])->raceDistance)->toBe('HALF')
        ->and(factsFor(['workout_type' => 1, 'distance' => 8_000.0])->raceDistance)->toBe('8K');
});

it('folds a race\'s kilometre splits into at most five printed cells', function (): void {
    $perKm = array_map(
        fn (int $km): array => ['km' => $km, 'pace' => '5:34', 'elapsed_sec' => 334],
        range(1, 10),
    );
    $facts = factsFor(['workout_type' => 1, 'stream_summary' => ['per_km' => $perKm]]);

    expect($facts->splits)->toHaveCount(5)
        ->and($facts->splits[0])->toBe(['2K', '11:08'])
        ->and($facts->splits[4])->toBe(['10K', '11:08']);
});

it('leaves the splits empty on a run that is not a race', function (): void {
    $perKm = [['km' => 1, 'pace' => '6:07', 'elapsed_sec' => 367]];

    expect(factsFor(['stream_summary' => ['per_km' => $perKm]])->splits)->toBe([]);
});

it('normalises the pace profile, and skips it below three splits', function (): void {
    $perKm = [
        ['km' => 1, 'elapsed_sec' => 400],
        ['km' => 2, 'elapsed_sec' => 300],
        ['km' => 3, 'elapsed_sec' => 350],
    ];

    expect(factsFor(['stream_summary' => ['per_km' => $perKm]])->paceProfile)->toBe([0.0, 1.0, 0.5])
        ->and(factsFor(['stream_summary' => ['per_km' => array_slice($perKm, 0, 2)]])->paceProfile)->toBe([]);
});

it('falls back to a readable place when the run was never geocoded', function (): void {
    $facts = factsFor(['location_name' => null]);

    expect($facts->place)->toBe('no location')
        ->and($facts->placeShort)->toBe('NO LOCATION');
});

it('shortens a geocoded place to its first segment for the tight labels', function (): void {
    expect(factsFor()->placeShort)->toBe('SENAYAN')
        ->and(factsFor()->place)->toBe('Senayan, Jakarta Pusat, Indonesia');
});

it('numbers the print from the card', function (): void {
    expect(factsFor()->serial)->toBe('TMR-0418');
});
