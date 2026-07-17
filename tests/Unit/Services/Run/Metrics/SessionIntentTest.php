<?php

declare(strict_types=1);

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
