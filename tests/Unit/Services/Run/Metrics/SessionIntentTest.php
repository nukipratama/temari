<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\ActivityDetail;
use App\Services\Run\Metrics\SessionIntent;

/**
 * @param  array<string, mixed>  $attrs
 */
function intentDetail(array $attrs): ActivityDetail
{
    return new ActivityDetail()->forceFill($attrs);
}

/** @param array<string, float> $zonePct */
function zones(array $zonePct): array
{
    return ['stream_summary' => ['time_in_zone_pct' => $zonePct]];
}

/** @param array<string, float> $zonePct */
function zonesWithReps(array $zonePct): array
{
    return ['stream_summary' => ['time_in_zone_pct' => $zonePct, 'laps' => [
        ['distance_m' => 1000, 'elapsed_sec' => 300],
        ['distance_m' => 400, 'elapsed_sec' => 180],
        ['distance_m' => 1000, 'elapsed_sec' => 300],
        ['distance_m' => 400, 'elapsed_sec' => 180],
        ['distance_m' => 1000, 'elapsed_sec' => 300],
    ]]];
}

it('reads a tagged Strava workout_type as the authoritative intent', function (int $code, string $intent): void {
    $result = SessionIntent::forDetail(intentDetail(['workout_type' => $code] + zones(['Z2' => 95])));

    expect($result)->toBe(['intent' => $intent, 'source' => 'tagged']);
})->with([
    'race' => [1, SessionIntent::RACE],
    'long run' => [2, SessionIntent::LONG_RUN],
    'workout' => [3, SessionIntent::WORKOUT],
]);

it('infers workout from a Z3-Z4 dominant untagged run (hard-zone share >= 80)', function (): void {
    // The activity 276 shape: 81.7% in Z3-Z4-Z5, high pace variability, untagged.
    $result = SessionIntent::forDetail(intentDetail(
        ['workout_type' => null] + zones(['Z1' => 4.7, 'Z2' => 13.5, 'Z3' => 47.1, 'Z4' => 34, 'Z5' => 0.6]),
    ));

    expect($result)->toBe(['intent' => SessionIntent::WORKOUT, 'source' => 'inferred']);
});

it('infers workout from a sustained threshold block even when hard-zone share is under 80', function (): void {
    // hardShare = 45 (< 80) but Z4 share 25 (>= 20): a tempo with a long easy warm-up.
    $result = SessionIntent::forDetail(intentDetail(
        ['workout_type' => 0] + zones(['Z1' => 15, 'Z2' => 40, 'Z3' => 20, 'Z4' => 25]),
    ));

    expect($result)->toBe(['intent' => SessionIntent::WORKOUT, 'source' => 'inferred']);
});

it('leaves a hot-drifting easy run as easy — zone shape excludes it (the crux)', function (): void {
    // High HR/decoupling from heat pushes some Z3, but it never sustains Z3-Z4:
    // hardShare 40, Z4 share 10 — both under threshold, so NOT a workout.
    $result = SessionIntent::forDetail(intentDetail(
        ['workout_type' => null] + zones(['Z1' => 20, 'Z2' => 40, 'Z3' => 30, 'Z4' => 10]),
    ));

    expect($result)->toBe(['intent' => SessionIntent::EASY, 'source' => 'none']);
});

it('returns unknown when there is no zone data to read', function (): void {
    expect(SessionIntent::forDetail(intentDetail(['workout_type' => null, 'stream_summary' => null])))
        ->toBe(['intent' => SessionIntent::UNKNOWN, 'source' => 'none']);
});

it('treats race and workout as intended-hard, everything else as not', function (): void {
    expect(SessionIntent::isIntendedHard(intentDetail(['workout_type' => 1] + zones(['Z2' => 95]))))->toBeTrue()
        ->and(SessionIntent::isIntendedHard(intentDetail(['workout_type' => 3] + zones(['Z2' => 95]))))->toBeTrue()
        ->and(SessionIntent::isIntendedHard(intentDetail(
            ['workout_type' => null] + zones(['Z3' => 50, 'Z4' => 35]),
        )))->toBeTrue() // inferred workout
        ->and(SessionIntent::isIntendedHard(intentDetail(['workout_type' => 2] + zones(['Z2' => 95]))))->toBeFalse()
        ->and(SessionIntent::isIntendedHard(intentDetail(['workout_type' => null] + zones(['Z2' => 95]))))->toBeFalse()
        ->and(SessionIntent::isIntendedHard(intentDetail(['workout_type' => null, 'stream_summary' => null])))->toBeFalse();
});

it('reads the planned session type when the run is untagged', function (SessionType $planned, string $intent): void {
    $result = SessionIntent::forDetail(intentDetail(['workout_type' => null] + zones(['Z2' => 95])), $planned);

    expect($result)->toBe(['intent' => $intent, 'source' => 'planned']);
})->with([
    'tempo' => [SessionType::Tempo, SessionIntent::WORKOUT],
    'interval' => [SessionType::Interval, SessionIntent::WORKOUT],
    'race' => [SessionType::Race, SessionIntent::RACE],
    'long' => [SessionType::Long, SessionIntent::LONG_RUN],
    'easy' => [SessionType::Easy, SessionIntent::EASY],
]);

it('lets a Strava tag outrank the plan', function (): void {
    $result = SessionIntent::forDetail(intentDetail(['workout_type' => 2] + zones(['Z2' => 95])), SessionType::Tempo);

    expect($result)->toBe(['intent' => SessionIntent::LONG_RUN, 'source' => 'tagged']);
});

it('reads structured rep laps as a workout on an easy or unplanned day', function (?SessionType $planned): void {
    $result = SessionIntent::forDetail(intentDetail(['workout_type' => null] + zonesWithReps(['Z2' => 95])), $planned);

    expect($result)->toBe(['intent' => SessionIntent::WORKOUT, 'source' => 'laps']);
})->with([
    'easy day' => [SessionType::Easy],
    'unplanned' => [null],
]);

it('falls back to the zone shape on an easy day run as threshold work', function (): void {
    $result = SessionIntent::forDetail(
        intentDetail(['workout_type' => null] + zones(['Z1' => 15, 'Z2' => 40, 'Z3' => 20, 'Z4' => 25])),
        SessionType::Easy,
    );

    expect($result)->toBe(['intent' => SessionIntent::WORKOUT, 'source' => 'inferred']);
});

it('keeps an easy day easy when it has no zone data to read', function (): void {
    expect(SessionIntent::forDetail(intentDetail(['workout_type' => null, 'stream_summary' => null]), SessionType::Easy))
        ->toBe(['intent' => SessionIntent::EASY, 'source' => 'planned']);
});

it('treats a planned tempo as intended-hard even on an easy-looking run', function (): void {
    expect(SessionIntent::isIntendedHard(intentDetail(['workout_type' => null] + zones(['Z2' => 95])), SessionType::Tempo))->toBeTrue();
});
