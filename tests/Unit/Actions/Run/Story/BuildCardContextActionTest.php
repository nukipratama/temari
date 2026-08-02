<?php

declare(strict_types=1);

use App\Actions\Run\Story\BuildCardContextAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\RunnerProfile;
use App\Models\User;
use App\Services\Run\Story\CardContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $attributes
 * @return array{0: Activity, 1: ActivityDetail}
 */
function cardContextSubject(User $user, array $attributes = []): array
{
    $activity = Activity::factory()->for($user)->create();
    $detail = ActivityDetail::factory()->for($activity)->create($attributes + [
        'distance' => 5_000,
        'start_date_local' => Carbon::parse('2026-05-15 07:00:00'),
    ]);

    return [$activity->refresh(), $detail];
}

function cardContextFor(Activity $activity, ActivityDetail $detail): CardContext
{
    return app(BuildCardContextAction::class)($activity, $detail);
}

it('flags the first run ever when the user has no other activity', function (): void {
    [$activity, $detail] = cardContextSubject(User::factory()->create());

    expect(cardContextFor($activity, $detail)->isFirstRunEver)->toBeTrue();
});

it('does not flag the first run ever once another analyzed activity exists', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->create();
    [$activity, $detail] = cardContextSubject($user);

    expect(cardContextFor($activity, $detail)->isFirstRunEver)->toBeFalse();
});

it('ignores un-ingested stubs when deciding the first run ever', function (): void {
    $user = User::factory()->create();
    Activity::factory()->for($user)->stub()->create();
    [$activity, $detail] = cardContextSubject($user);

    expect(cardContextFor($activity, $detail)->isFirstRunEver)->toBeTrue();
});

it('flags the first distance bracket when no earlier run reached it', function (): void {
    $user = User::factory()->create();
    $earlier = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($earlier)->create([
        'distance' => 3_000,
        'start_date_local' => Carbon::parse('2026-04-01 07:00:00'),
    ]);
    [$activity, $detail] = cardContextSubject($user, ['distance' => 10_500]);

    expect(cardContextFor($activity, $detail)->isFirstDistanceBracket)->toBeTrue();
});

it('does not flag the distance bracket when an earlier run already reached it', function (): void {
    $user = User::factory()->create();
    $earlier = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($earlier)->create([
        'distance' => 11_000,
        'start_date_local' => Carbon::parse('2026-04-01 07:00:00'),
    ]);
    [$activity, $detail] = cardContextSubject($user, ['distance' => 10_500]);

    expect(cardContextFor($activity, $detail)->isFirstDistanceBracket)->toBeFalse();
});

it('does not flag a distance bracket for a run below the smallest bracket', function (): void {
    [$activity, $detail] = cardContextSubject(User::factory()->create(), ['distance' => 2_000]);

    expect(cardContextFor($activity, $detail)->isFirstDistanceBracket)->toBeFalse();
});

it('does not flag a distance bracket for a zero-distance run', function (): void {
    [$activity, $detail] = cardContextSubject(User::factory()->create(), ['distance' => 0]);

    expect(cardContextFor($activity, $detail)->isFirstDistanceBracket)->toBeFalse();
});

it('flags weekly consistency with 3 runs in the same Mon-Sun week', function (): void {
    $user = User::factory()->create();
    $monday = Carbon::parse('2026-05-11 07:00:00');

    foreach ([0, 2] as $offsetDays) {
        $other = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($other)->create([
            'start_date_local' => $monday->copy()->addDays($offsetDays),
        ]);
    }

    [$activity, $detail] = cardContextSubject($user, ['start_date_local' => $monday->copy()->addDays(4)]);

    expect(cardContextFor($activity, $detail)->weeklyConsistency)->toBeTrue();
});

it('does not flag weekly consistency with only 2 runs in the week', function (): void {
    $user = User::factory()->create();
    $monday = Carbon::parse('2026-05-11 07:00:00');

    $other = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($other)->create([
        'start_date_local' => $monday->copy()->addDays(1),
    ]);

    [$activity, $detail] = cardContextSubject($user, ['start_date_local' => $monday->copy()->addDays(4)]);

    expect(cardContextFor($activity, $detail)->weeklyConsistency)->toBeFalse();
});

it('does not flag weekly consistency when a 3rd run falls in the following week', function (): void {
    $user = User::factory()->create();
    $monday = Carbon::parse('2026-05-11 07:00:00');

    $other = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($other)->create([
        'start_date_local' => $monday->copy()->addDays(1),
    ]);
    $nextWeek = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($nextWeek)->create([
        'start_date_local' => $monday->copy()->addWeek(),
    ]);

    [$activity, $detail] = cardContextSubject($user, ['start_date_local' => $monday->copy()->addDays(4)]);

    expect(cardContextFor($activity, $detail)->weeklyConsistency)->toBeFalse();
});

it('returns false for weekly consistency when the activity has no start date', function (): void {
    [$activity, $detail] = cardContextSubject(User::factory()->create(), ['start_date_local' => null]);

    expect(cardContextFor($activity, $detail)->weeklyConsistency)->toBeFalse();
});

it('counts the consecutive running days before the activity', function (): void {
    $user = User::factory()->create();
    $day = Carbon::parse('2026-05-15 07:00:00');

    foreach ([1, 2, 3] as $daysBack) {
        $other = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($other)->create([
            'start_date_local' => $day->copy()->subDays($daysBack),
        ]);
    }

    [$activity, $detail] = cardContextSubject($user, ['start_date_local' => $day]);

    expect(cardContextFor($activity, $detail)->consecutiveDaysBefore)->toBe(3);
});

it('stops the streak at the first missing day', function (): void {
    $user = User::factory()->create();
    $day = Carbon::parse('2026-05-15 07:00:00');

    foreach ([1, 3] as $daysBack) {
        $other = Activity::factory()->for($user)->create();
        ActivityDetail::factory()->for($other)->create([
            'start_date_local' => $day->copy()->subDays($daysBack),
        ]);
    }

    [$activity, $detail] = cardContextSubject($user, ['start_date_local' => $day]);

    expect(cardContextFor($activity, $detail)->consecutiveDaysBefore)->toBe(1);
});

it('reports a zero streak when the activity has no start date', function (): void {
    [$activity, $detail] = cardContextSubject(User::factory()->create(), ['start_date_local' => null]);

    expect(cardContextFor($activity, $detail)->consecutiveDaysBefore)->toBe(0);
});

it('resolves the athlete max HR when the run has an average HR', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 193, 'resting_hr' => 50]);
    [$activity, $detail] = cardContextSubject($user, ['average_heartrate' => 150]);

    expect(cardContextFor($activity, $detail)->athleteMaxHr)->toBe(193);
});

it('leaves the athlete max HR unresolved when the run has no average HR', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 193, 'resting_hr' => 50]);
    [$activity, $detail] = cardContextSubject($user, ['average_heartrate' => null]);

    expect(cardContextFor($activity, $detail)->athleteMaxHr)->toBeNull();
});

it('resolves the whole history in two queries', function (): void {
    $user = User::factory()->create();
    RunnerProfile::factory()->for($user)->create(['max_hr' => 193, 'resting_hr' => 50]);
    [$activity, $detail] = cardContextSubject($user, ['average_heartrate' => 150]);
    $activity->load('detail', 'user.runnerProfile');

    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    cardContextFor($activity, $detail);

    expect($queries)->toBe(2);
});
