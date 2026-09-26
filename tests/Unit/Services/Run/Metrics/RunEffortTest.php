<?php

declare(strict_types=1);

use App\Enums\Effort;
use App\Enums\SessionType;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\Run\Metrics\RunEffort;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $attributes */
function runEffortDetail(User $user, array $attributes): ActivityDetail
{
    $activity = Activity::factory()->for($user)->analyzed()->create();

    return ActivityDetail::factory()->for($activity)->create(array_merge([
        'start_date_local' => '2026-09-10 07:00:00',
        'elapsed_time' => 40 * 60,
        'workout_type' => null,
        'stream_summary' => null,
    ], $attributes));
}

/** @return array<string, float> */
function runEffortZones(float $z1, float $z2, float $z3, float $z4, float $z5): array
{
    return ['time_in_zone_pct' => ['Z1' => $z1, 'Z2' => $z2, 'Z3' => $z3, 'Z4' => $z4, 'Z5' => $z5]];
}

it('lets the matched planned session win over the Strava tag and heart rate', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-10', 'session_type' => SessionType::Easy]);
    $detail = runEffortDetail($user, ['workout_type' => 1, 'stream_summary' => runEffortZones(0, 0, 10, 40, 50)]);

    expect(RunEffort::forDetails($user->id, collect([$detail])))->toBe([$detail->activity_id => Effort::Easy]);
});

it('classifies an unplanned run from its tag or its zones', function (?int $workoutType, ?array $summary, int $seconds, Effort $effort): void {
    $user = User::factory()->create();
    $detail = runEffortDetail($user, ['workout_type' => $workoutType, 'stream_summary' => $summary, 'elapsed_time' => $seconds]);

    expect(RunEffort::forDetails($user->id, collect([$detail])))->toBe([$detail->activity_id => $effort]);
})->with([
    'tagged race' => [1, null, 40 * 60, Effort::Hard],
    'tagged long run' => [2, null, 40 * 60, Effort::Steady],
    'threshold-heavy zones' => [null, runEffortZones(10, 30, 30, 25, 5), 40 * 60, Effort::Hard],
    'aerobic zones' => [null, runEffortZones(40, 55, 5, 0, 0), 40 * 60, Effort::Easy],
    'aerobic zones, long' => [null, runEffortZones(40, 55, 5, 0, 0), 100 * 60, Effort::Steady],
    'no heart rate' => [null, null, 40 * 60, Effort::Unknown],
]);

it('resolves several runs across days in one call', function (): void {
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => '2026-09-12', 'session_type' => SessionType::Interval]);
    $unplanned = runEffortDetail($user, ['start_date_local' => '2026-09-10 07:00:00', 'stream_summary' => runEffortZones(40, 55, 5, 0, 0)]);
    $planned = runEffortDetail($user, ['start_date_local' => '2026-09-12 07:00:00']);

    expect(RunEffort::forDetails($user->id, collect([$unplanned, $planned])))->toBe([
        $unplanned->activity_id => Effort::Easy,
        $planned->activity_id => Effort::Hard,
    ]);
});

it('returns nothing for no runs', function (): void {
    expect(RunEffort::forDetails(User::factory()->create()->id, collect()))->toBe([]);
});
