<?php

declare(strict_types=1);

use App\Enums\IngestState;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Story\ComparableRun;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function seedRun(User $user, Carbon $when, float $distanceM, int $movingTimeSec, array $overrides = []): ActivityDetail
{
    $activity = Activity::factory()->for($user)->analyzed()->create();

    return ActivityDetail::factory()->for($activity)->create(array_merge([
        'distance' => $distanceM,
        'moving_time' => $movingTimeSec,
        'elapsed_time' => $movingTimeSec,
        'start_date_local' => $when,
    ], $overrides));
}

it('returns null when the user has no history', function (): void {
    $user = User::factory()->create();
    $current = seedRun($user, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('matches the oldest qualifying easy run within ±20% distance', function (): void {
    $user = User::factory()->create();

    // Pin temps so the factory's random weather doesn't blow the temp tolerance.
    $temp = ['weather_temp_c' => 27];
    seedRun($user, Carbon::today()->subDays(45), 10_500, 4_410, $temp);
    seedRun($user, Carbon::today()->subDays(90), 9_700, 4_074, $temp);
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, $temp);

    $current = seedRun($user, Carbon::today(), 10_000, 4_140, $temp);
    $match = app(PastYouMatcher::class)->findMatch($current->activity, $current);

    expect($match)->not->toBeNull()
        ->and($match['past']->start_date_local->toDateString())->toBe(Carbon::today()->subDays(90)->toDateString())
        ->and($match['days_ago'])->toBe(90)
        ->and($match['pace_diff_sec'])->toBeFloat()->toBeGreaterThan(0);
});

it('rejects matches less than 21 days apart', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(10), 10_000, 4_200);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('accepts a match exactly 21 days apart', function (): void {
    $user = User::factory()->create();
    $temp = ['weather_temp_c' => 27];
    seedRun($user, Carbon::today()->subDays(21), 10_000, 4_200, $temp);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200, $temp);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->not->toBeNull();
});

it('rejects a match exactly 20 days apart', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(20), 10_000, 4_200);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('rejects matches in a different pace band', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 10_000, 3_300);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('rejects matches outside the ±20% distance window', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 5_000, 2_100);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('accepts a match exactly 500m off', function (): void {
    $user = User::factory()->create();
    $temp = ['weather_temp_c' => 27];
    seedRun($user, Carbon::today()->subDays(60), 10_500, 4_410, $temp);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200, $temp);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->not->toBeNull();
});

it('rejects a match 501m off', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 10_501, 4_410);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('rejects matches outside the ±3°C temp window when both have weather', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, ['weather_temp_c' => 22]);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200, ['weather_temp_c' => 32]);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('accepts a match exactly 3°C off', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, ['weather_temp_c' => 24]);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200, ['weather_temp_c' => 27]);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->not->toBeNull();
});

it('rejects a match 4°C off', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, ['weather_temp_c' => 23]);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200, ['weather_temp_c' => 27]);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('accepts matches when one side is missing weather', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, ['weather_temp_c' => null]);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200, ['weather_temp_c' => 30]);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->not->toBeNull();
});

it('reports HR diff when both sides have it', function (): void {
    $user = User::factory()->create();
    // Pin temps so factory weather randomness doesn't fail the temp tolerance.
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, [
        'average_heartrate' => 160.0,
        'weather_temp_c' => 27,
    ]);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200, [
        'average_heartrate' => 152.0,
        'weather_temp_c' => 27,
    ]);

    $match = app(PastYouMatcher::class)->findMatch($current->activity, $current);
    expect($match['hr_diff_bpm'])->toBeFloat()->toEqualWithDelta(-8.0, 0.01);
});

it('ignores other users\' history', function (): void {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    seedRun($userB, Carbon::today()->subDays(60), 10_000, 4_200);

    $current = seedRun($userA, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('returns null when the current activity has no distance', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 0,
        'moving_time' => 1800,
        'start_date_local' => Carbon::today(),
    ]);

    expect(app(PastYouMatcher::class)->findMatch($activity, $detail))->toBeNull();
});

it('returns null when the current activity has no start_date_local', function (): void {
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'distance' => 5_000,
        'moving_time' => 1_800,
        'start_date_local' => null,
    ]);

    expect(app(PastYouMatcher::class)->findMatch($activity, $detail))->toBeNull();
});

it('reports a null hr_diff when one side is missing average_heartrate', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, [
        'average_heartrate' => 160.0,
        'weather_temp_c' => 27,
    ]);
    $current = seedRun($user, Carbon::today(), 10_000, 4_200, [
        'average_heartrate' => null,
        'weather_temp_c' => 27,
    ]);

    $match = app(PastYouMatcher::class)->findMatch($current->activity, $current);

    expect($match['hr_diff_bpm'])->toBeNull();
});

it('classifies pace into recovery, easy, and threshold bands', function (): void {
    $matcher = new PastYouMatcher();

    expect($matcher->paceBand(460))->toBe(PastYouMatcher::BAND_RECOVERY)
        ->and($matcher->paceBand(420))->toBe(PastYouMatcher::BAND_EASY)
        ->and($matcher->paceBand(350))->toBe(PastYouMatcher::BAND_THRESHOLD);
});

it('classifies pace bands exactly at the floor boundaries', function (): void {
    $matcher = new PastYouMatcher();

    expect($matcher->paceBand(450.0))->toBe(PastYouMatcher::BAND_RECOVERY)
        ->and($matcher->paceBand(449.9))->toBe(PastYouMatcher::BAND_EASY)
        ->and($matcher->paceBand(390.0))->toBe(PastYouMatcher::BAND_EASY)
        ->and($matcher->paceBand(389.9))->toBe(PastYouMatcher::BAND_THRESHOLD);
});

// The matcher takes the OLDEST qualifying run so the contrast reads as progress.
// With no upper bound that reached the whole account: real narration compared a
// runner to a session 2005 days earlier, which is a different person.
it('ignores a comparison run older than a year', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(400), 10_000, 4_500, ['weather_temp_c' => 27]);
    $current = seedRun($user, Carbon::today(), 10_000, 4_200, ['weather_temp_c' => 27]);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('still matches a run just inside the year boundary', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(360), 10_000, 4_400, ['weather_temp_c' => 27]);
    $current = seedRun($user, Carbon::today(), 10_000, 4_200, ['weather_temp_c' => 27]);

    $match = app(PastYouMatcher::class)->findMatch($current->activity, $current);

    expect($match)->not->toBeNull()
        ->and($match['days_ago'])->toBe(360);
});

it('prefers a run inside the window over an older one', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(900), 10_000, 4_440, ['weather_temp_c' => 27]);
    seedRun($user, Carbon::today()->subDays(300), 10_000, 4_400, ['weather_temp_c' => 27]);
    $current = seedRun($user, Carbon::today(), 10_000, 4_200, ['weather_temp_c' => 27]);

    $match = app(PastYouMatcher::class)->findMatch($current->activity, $current);

    expect($match['days_ago'])->toBe(300);
});

function matcherRun(
    string $date,
    float $paceSecPerKm,
    float $distanceM = 10_000.0,
    ?float $hr = 155.0,
    ?float $elevationM = 50.0,
    int $activityId = 1,
): ComparableRun {
    return new ComparableRun(
        activityId: $activityId,
        startedAt: Carbon::parse($date),
        distanceM: $distanceM,
        movingTimeSec: (int) round($paceSecPerKm * $distanceM / 1000),
        paceSecPerKm: $paceSecPerKm,
        averageHeartrate: $hr,
        elevationGainM: $elevationM,
        ingestState: IngestState::Summary,
    );
}

it('scores a perfectly comparable pair at the top of the scale', function (): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 06:00:00', 420.0);
    $past = matcherRun('2025-06-15 06:00:00', 430.0);

    expect($matcher->similarity($current, $past))->toEqualWithDelta(1.0, 0.0001);
});

it('rejects a pairing that breaks a hard rule, reading only summary fields', function (ComparableRun $past): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 06:00:00', 420.0);

    expect($matcher->similarity($current, $past))->toBeNull();
})->with([
    'too recent' => fn (): ComparableRun => matcherRun('2026-06-01 06:00:00', 420.0),
    'older than the ceiling' => fn (): ComparableRun => matcherRun('2025-05-01 06:00:00', 420.0),
    'different pace band' => fn (): ComparableRun => matcherRun('2026-01-15 06:00:00', 460.0),
    'too far off on distance' => fn (): ComparableRun => matcherRun('2026-01-15 06:00:00', 420.0, 10_501.0),
    'a hill run against a flat one' => fn (): ComparableRun => matcherRun('2026-01-15 06:00:00', 420.0, 10_000.0, 155.0, 220.0),
]);

it('scores a summary-state run with no heart rate or elevation', function (): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 06:00:00', 420.0, 10_000.0, null, null);
    $past = matcherRun('2026-01-15 06:00:00', 430.0, 10_000.0, null, null);

    expect($matcher->similarity($current, $past))->toBeFloat()->toBeGreaterThan(0.0);
});

it('penalises a pairing run at a very different hour', function (): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 06:00:00', 420.0);
    $morning = matcherRun('2026-01-15 06:00:00', 420.0);
    $evening = matcherRun('2026-01-15 18:00:00', 420.0);

    expect($matcher->similarity($current, $morning))
        ->toBeGreaterThan($matcher->similarity($current, $evening));
});

it('treats midnight as a wrap-around rather than a twelve-hour gap', function (): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 23:30:00', 420.0);
    $nearby = matcherRun('2026-01-15 00:30:00', 420.0);
    $distant = matcherRun('2026-01-15 11:30:00', 420.0);

    expect($matcher->similarity($current, $nearby))
        ->toBeGreaterThan($matcher->similarity($current, $distant));
});

it('prefers a pairing from the same season', function (): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 06:00:00', 420.0);
    $sameSeason = matcherRun('2025-07-15 06:00:00', 420.0);
    $offSeason = matcherRun('2025-12-15 06:00:00', 420.0);

    expect($matcher->similarity($current, $sameSeason))
        ->toBeGreaterThan($matcher->similarity($current, $offSeason));
});

it('picks the most comparable candidate, not the nearest in time', function (): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 06:00:00', 420.0);
    $comparison = $matcher->bestMatch($current, [
        matcherRun('2026-05-01 18:00:00', 420.0, 10_450.0, 155.0, 50.0, 11),
        matcherRun('2025-07-10 06:00:00', 430.0, 10_000.0, 155.0, 50.0, 12),
    ]);

    expect($comparison)->not->toBeNull()
        ->and($comparison->past->activityId)->toBe(12)
        ->and($comparison->paceDeltaSec)->toBe(10.0);
});

it('breaks a tie towards the older run', function (): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 06:00:00', 420.0);
    $comparison = $matcher->bestMatch($current, [
        matcherRun('2026-04-15 06:00:00', 420.0, 10_000.0, 155.0, 50.0, 21),
        matcherRun('2025-08-15 06:00:00', 420.0, 10_000.0, 155.0, 50.0, 22),
    ]);

    expect($comparison->past->activityId)->toBe(22);
});

it('finds no best match when nothing qualifies', function (): void {
    $matcher = new PastYouMatcher();
    $current = matcherRun('2026-06-15 06:00:00', 420.0);

    expect($matcher->bestMatch($current, [matcherRun('2026-06-10 06:00:00', 420.0)]))->toBeNull()
        ->and($matcher->bestMatch($current, []))->toBeNull();
});
