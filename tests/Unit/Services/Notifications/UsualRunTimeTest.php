<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Notifications\UsualRunTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** Each run lands one day after the last one this test created, oldest first. */
function runsStartingAt(User $user, string ...$times): void
{
    static $day = 0;

    foreach ($times as $time) {
        $activity = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($activity)->create([
            'start_date_local' => Carbon::parse('2026-01-01 '.$time)->addDays($day++),
        ]);
    }
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
