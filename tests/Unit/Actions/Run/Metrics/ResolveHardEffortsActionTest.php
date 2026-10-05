<?php

declare(strict_types=1);

use App\Actions\Run\Metrics\ResolveHardEffortsAction;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\PaceFormatter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function hardEffortRun(User $user, string $date, float $meters, int $secPerKm, ?int $workoutType = 0, bool $splits = true): Activity
{
    $rows = [];
    for ($km = 1; $km <= (int) floor($meters / 1000); $km++) {
        $rows[] = ['km' => $km, 'pace' => PaceFormatter::format((float) $secPerKm), 'elapsed_sec' => $secPerKm, 'distance_m' => 1000];
    }
    $seconds = (int) round($meters / 1000 * $secPerKm);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $date.' 06:00:00', 'distance' => $meters, 'elapsed_time' => $seconds, 'moving_time' => $seconds,
        'workout_type' => $workoutType, 'stream_summary' => $splits ? ['per_km' => $rows] : null,
    ]);

    return $activity;
}

it('keeps a whole-run record at its record distance and a Race-tagged run without one as a run', function (): void {
    $user = User::factory()->create();
    $race = hardEffortRun($user, '2026-09-01', 8_000, 330, 1, false);
    $record = hardEffortRun($user, '2026-09-10', 5_200, 300);

    $resolved = new ResolveHardEffortsAction()($user->id);
    $efforts = $resolved['efforts'];

    expect($efforts)->toHaveCount(1)
        ->and(array_column($resolved['runs'], 'activity_id'))->toBe([$race->id])
        ->and($efforts[0])->toMatchArray(['activity_id' => $record->id, 'distance_m' => 5_000.0, 'time_sec' => 1_500.0, 'basis' => '5km']);
});

it('judges a record against every earlier run, embedded segments included', function (): void {
    $user = User::factory()->create();
    $long = hardEffortRun($user, '2026-09-01', 12_000, 290);
    $five = hardEffortRun($user, '2026-09-10', 5_000, 300);

    $resolved = new ResolveHardEffortsAction()($user->id);

    expect($resolved['efforts'])->toBe([])
        ->and(array_column($resolved['runs'], 'activity_id'))->toBe([$long->id, $five->id]);
});

it('keeps runs under 3 km out of both lists', function (): void {
    $user = User::factory()->create();
    hardEffortRun($user, '2026-09-01', 2_000, 280, 1);

    expect(new ResolveHardEffortsAction()($user->id))->toBe(['efforts' => [], 'runs' => []]);
});

it('reads once per request until forgotten', function (): void {
    $user = User::factory()->create();
    $action = new ResolveHardEffortsAction();
    $action($user->id);
    hardEffortRun($user, '2026-09-01', 5_000, 330);

    expect($action($user->id)['efforts'])->toBe([]);

    $action->forget($user->id);

    expect($action($user->id)['efforts'])->toHaveCount(1);
});
