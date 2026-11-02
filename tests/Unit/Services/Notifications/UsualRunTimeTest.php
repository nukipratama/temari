<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Notifications\UsualRunTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-06-03 05:00:00'));

afterEach(fn () => Carbon::setTestNow());

/**
 * Each run lands on a later day than the last one this test created, oldest first, never on a
 * Wednesday, so today's weekday never has a median of its own.
 */
function runsStartingAt(User $user, string ...$times): void
{
    static $day = 0;

    foreach ($times as $time) {
        do {
            $start = Carbon::parse('2026-01-01 '.$time)->addDays($day++);
        } while ($start->isWednesday());

        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create(['start_date_local' => $start]);
    }
}

function runOnTodaysWeekday(User $user, int $weeksAgo, string $time): void
{
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-06-03 '.$time)->subWeeks($weeksAgo),
    ]);
}

it('falls back to 06:00 for an athlete with too little history', function (int $runs): void {
    $user = User::factory()->create();
    runsStartingAt($user, ...array_fill(0, $runs, '19:00:00'));

    expect(new UsualRunTime()->forUser($user->id))->toBe(UsualRunTime::FALLBACK_MINUTE);
})->with([0, 1, 4]);

it('takes the middle sample on an odd count', function (): void {
    $user = User::factory()->create();
    runsStartingAt($user, '05:00:00', '05:30:00', '06:00:00', '06:30:00', '19:00:00');

    expect(new UsualRunTime()->forUser($user->id))->toBe(6 * 60);
});

it('averages the two middle samples on an even count', function (): void {
    $user = User::factory()->create();
    runsStartingAt($user, '05:00:00', '05:30:00', '06:00:00', '06:30:00', '07:00:00', '19:00:00');

    expect(new UsualRunTime()->forUser($user->id))->toBe(6 * 60 + 15);
});

// A single night race must not drag the answer the way a mean would: the mean
// of these six is 08:05, the median is 06:00.
it('is unmoved by one outlying start', function (): void {
    $user = User::factory()->create();
    runsStartingAt($user, '05:30:00', '05:45:00', '06:00:00', '06:00:00', '06:15:00', '21:00:00');

    expect(new UsualRunTime()->forUser($user->id))->toBe(6 * 60);
});

// start_date_local is a naive wall clock, not an instant: whatever the process
// timezone, 05:30 on the row means 05:30 to the athlete who ran it.
it('reads start_date_local as a wall clock, never shifted', function (): void {
    $user = User::factory()->create();
    runsStartingAt($user, '05:30:00', '05:30:00', '05:30:00', '05:30:00', '05:30:00');

    $original = date_default_timezone_get();
    date_default_timezone_set('UTC');

    try {
        expect(new UsualRunTime()->forUser($user->id))->toBe(5 * 60 + 30);
    } finally {
        date_default_timezone_set($original);
    }
});

it('reads only the most recent runs', function (): void {
    $user = User::factory()->create();
    runsStartingAt($user, ...array_fill(0, UsualRunTime::SAMPLE_SIZE, '19:00:00'));
    // Newer than every one of those, since runsStartingAt walks the month forward.
    runsStartingAt($user, ...array_fill(0, UsualRunTime::SAMPLE_SIZE, '06:00:00'));

    expect(new UsualRunTime()->forUser($user->id))->toBe(6 * 60);
});

it('ignores another athlete\'s runs', function (): void {
    $user = User::factory()->create();
    runsStartingAt(User::factory()->create(), '19:00:00', '19:00:00', '19:00:00', '19:00:00', '19:00:00');

    expect(new UsualRunTime()->forUser($user->id))->toBe(UsualRunTime::FALLBACK_MINUTE);
});

it('caches the computed slot for the day, so a second call within the day skips the query', function (): void {
    $user = User::factory()->create();
    runsStartingAt($user, '05:00:00', '05:30:00', '06:00:00', '06:30:00', '07:00:00');

    $usualRunTime = new UsualRunTime();
    expect($usualRunTime->forUser($user->id))->toBe(6 * 60);

    ActivityDetail::query()->update(['start_date_local' => Carbon::parse('2026-06-01 19:00:00')]);

    expect($usualRunTime->forUser($user->id))->toBe(6 * 60);
});

it('busts the cached slot when clearCache is called for that athlete', function (): void {
    $user = User::factory()->create();
    runsStartingAt($user, '05:00:00', '05:30:00', '06:00:00', '06:30:00', '07:00:00');

    $usualRunTime = new UsualRunTime();
    expect($usualRunTime->forUser($user->id))->toBe(6 * 60);

    ActivityDetail::query()->update(['start_date_local' => Carbon::parse('2026-06-01 19:00:00')]);
    UsualRunTime::clearCache($user);

    expect($usualRunTime->forUser($user->id))->toBe(19 * 60);
});

it('returns int when the cache round-trips the value as a numeric string', function (): void {
    $user = User::factory()->create();
    Cache::put(UsualRunTime::cacheKey($user->id, Carbon::today()->toDateString()), '360', now()->addDay());

    $result = new UsualRunTime()->forUser($user->id);

    expect($result)->toBe(360)->toBeInt();
});

it('keys the cache per athlete and per day', function (): void {
    $user = User::factory()->create();
    $today = Carbon::today()->toDateString();

    expect(Cache::has(UsualRunTime::cacheKey($user->id, $today)))->toBeFalse();

    new UsualRunTime()->forUser($user->id);

    expect(Cache::has(UsualRunTime::cacheKey($user->id, $today)))->toBeTrue();
});

it('takes the median of today\'s weekday once that weekday has enough runs', function (): void {
    $user = User::factory()->create();
    foreach (['05:30:00', '05:40:00', '05:45:00', '05:50:00', '06:00:00'] as $weeksAgo => $time) {
        runOnTodaysWeekday($user, $weeksAgo + 1, $time);
    }
    runsStartingAt($user, ...array_fill(0, 10, '18:00:00'));

    expect(new UsualRunTime()->forUser($user->id))->toBe(5 * 60 + 45);
});

it('falls back to the overall median when today\'s weekday has too few runs', function (): void {
    $user = User::factory()->create();
    foreach (range(1, UsualRunTime::MIN_SAMPLES - 1) as $weeksAgo) {
        runOnTodaysWeekday($user, $weeksAgo, '05:30:00');
    }
    runsStartingAt($user, ...array_fill(0, 6, '18:00:00'));

    expect(new UsualRunTime()->forUser($user->id))->toBe(18 * 60);
});

it('reads only the most recent runs on today\'s weekday', function (): void {
    $user = User::factory()->create();
    foreach (range(1, UsualRunTime::WEEKDAY_SAMPLE_SIZE) as $weeksAgo) {
        runOnTodaysWeekday($user, $weeksAgo, '06:00:00');
        runOnTodaysWeekday($user, $weeksAgo + UsualRunTime::WEEKDAY_SAMPLE_SIZE, '19:00:00');
    }

    expect(new UsualRunTime()->forUser($user->id))->toBe(6 * 60);
});
