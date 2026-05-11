<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Story\PastYouMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Helper: make an analyzed activity + detail for a user with the given metrics.
 *
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

    // Three past easies of the right pace band + within distance tolerance.
    // Pin temps so the factory's random weather doesn't blow the temp tolerance.
    $temp = ['weather_temp_c' => 27];
    seedRun($user, Carbon::today()->subDays(45), 10_500, 4_410, $temp); // 7:00/km easy, +5% distance
    seedRun($user, Carbon::today()->subDays(90), 9_700, 4_074, $temp);  // 7:00/km easy, -3% distance — OLDEST
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, $temp); // exact match in the middle

    $current = seedRun($user, Carbon::today(), 10_000, 4_140, $temp); // 6:54/km easy
    $match = app(PastYouMatcher::class)->findMatch($current->activity, $current);

    expect($match)->not->toBeNull()
        ->and($match['past']->start_date_local->toDateString())->toBe(Carbon::today()->subDays(90)->toDateString())
        ->and($match['days_ago'])->toBe(90)
        // 7:00/km past vs 6:54/km current = +6 sec/km faster today
        ->and($match['pace_diff_sec'])->toBeFloat()->toBeGreaterThan(0);
});

it('rejects matches less than 21 days apart', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(10), 10_000, 4_200);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('rejects matches in a different pace band', function (): void {
    $user = User::factory()->create();
    // Past is a tempo (5:30/km = 330s) — different band from current easy.
    seedRun($user, Carbon::today()->subDays(60), 10_000, 3_300);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200); // 7:00/km easy

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('rejects matches outside the ±20% distance window', function (): void {
    $user = User::factory()->create();
    // Past run is ~5km — too short for a 10K comparison
    seedRun($user, Carbon::today()->subDays(60), 5_000, 2_100);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200);

    expect(app(PastYouMatcher::class)->findMatch($current->activity, $current))->toBeNull();
});

it('rejects matches outside the ±3°C temp window when both have weather', function (): void {
    $user = User::factory()->create();
    seedRun($user, Carbon::today()->subDays(60), 10_000, 4_200, ['weather_temp_c' => 22]);

    $current = seedRun($user, Carbon::today(), 10_000, 4_200, ['weather_temp_c' => 32]);

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
    // Pin temps to the same value so the temp-tolerance filter doesn't randomly reject.
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
    // Past run has avg_hr, current doesn't.
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
