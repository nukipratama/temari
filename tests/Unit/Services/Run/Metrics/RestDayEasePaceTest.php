<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\RestDayEasePace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

$asOf = fn (): Carbon => Carbon::parse('2026-06-15 08:00:00');

/** One run (activity + detail) at the given elapsed-time pace, on the given day. */
function easePaceRun(User $user, Carbon $day, float $distanceM, float $secPerKm): void
{
    $seconds = (int) round($secPerKm * $distanceM / 1000);
    $activity = Activity::factory()->for($user)->analyzed()->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $day->copy()->setTime(7, 0),
        'distance' => $distanceM,
        // Deliberately different from elapsed_time: proves classification and
        // the pace math both run on elapsed time, never moving time.
        'moving_time' => $seconds - 60,
        'elapsed_time' => $seconds,
    ]);
}

/**
 * $afterRest easy runs (no run the day before) at $afterRestPace, and
 * $others easy runs (a filler run the day before) at $othersPace, spread
 * across distinct days inside the 12-week window.
 */
function seedEaseSamples(
    User $user,
    Carbon $asOf,
    int $afterRest,
    float $afterRestPace,
    int $others,
    float $othersPace,
): void {
    for ($i = 1; $i <= $afterRest; $i++) {
        easePaceRun($user, $asOf->copy()->subDays(10 + $i * 3), 8000.0, $afterRestPace);
    }

    for ($j = 1; $j <= $others; $j++) {
        $day = $asOf->copy()->subDays(40 + $j * 3);
        easePaceRun($user, $day->copy()->subDay(), 5000.0, 600.0);
        easePaceRun($user, $day, 8000.0, $othersPace);
    }
}

it('reports the after-rest easy pace as quicker against every other easy run', function () use ($asOf): void {
    $user = User::factory()->create();
    seedEaseSamples($user, $asOf(), 5, 400.0, 5, 412.0);

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))->toBe([
        'deltaSecPerKm' => 12.0,
        'direction' => 'quicker',
    ]);
});

it('reports the after-rest easy pace as slower when it runs hotter', function () use ($asOf): void {
    $user = User::factory()->create();
    seedEaseSamples($user, $asOf(), 5, 420.0, 5, 405.0);

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))->toBe([
        'deltaSecPerKm' => 15.0,
        'direction' => 'slower',
    ]);
});

it('returns null when the after-rest side has fewer than 5 samples', function () use ($asOf): void {
    $user = User::factory()->create();
    seedEaseSamples($user, $asOf(), 4, 400.0, 5, 412.0);

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))->toBeNull();
});

it('returns null when the other-easy-run side has fewer than 5 samples', function () use ($asOf): void {
    $user = User::factory()->create();
    seedEaseSamples($user, $asOf(), 5, 400.0, 4, 412.0);

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))->toBeNull();
});

it('returns null with no running history at all', function () use ($asOf): void {
    $user = User::factory()->create();

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))->toBeNull();
});

it('ignores runs outside the 12-week window', function () use ($asOf): void {
    $user = User::factory()->create();
    seedEaseSamples($user, $asOf(), 5, 400.0, 5, 412.0);

    // A 6th after-rest sample well outside the window, at an easy pace that
    // would shift the mean if counted, must not tip either side.
    easePaceRun($user, $asOf()->copy()->subWeeks(20), 8000.0, 430.0);

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))->toBe([
        'deltaSecPerKm' => 12.0,
        'direction' => 'quicker',
    ]);
});

it('excludes runs outside the easy pace band from both sides', function () use ($asOf): void {
    $user = User::factory()->create();
    seedEaseSamples($user, $asOf(), 5, 400.0, 5, 412.0);

    // A threshold-paced run the day after a no-run day: not easy, so it
    // must not count toward the after-rest side.
    easePaceRun($user, $asOf()->copy()->subDays(70), 8000.0, 300.0);

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))->toBe([
        'deltaSecPerKm' => 12.0,
        'direction' => 'quicker',
    ]);
});

it('returns null when the gap is under the display threshold', function () use ($asOf): void {
    $user = User::factory()->create();
    seedEaseSamples($user, $asOf(), 5, 400.0, 5, 404.0);

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))
        ->toBeNull()
        ->and(RestDayEasePace::MIN_DELTA_SEC_PER_KM)->toBe(5.0);
});

it('shows the gap once it reaches the display threshold', function () use ($asOf): void {
    $user = User::factory()->create();
    seedEaseSamples($user, $asOf(), 5, 400.0, 5, 405.0);

    expect(app(RestDayEasePace::class)->forUser($user, $asOf()))->toBe([
        'deltaSecPerKm' => 5.0,
        'direction' => 'quicker',
    ]);
});
