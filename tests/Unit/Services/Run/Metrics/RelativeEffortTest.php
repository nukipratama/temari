<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\RelativeEffort;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

$asOf = fn (): Carbon => Carbon::parse('2026-06-15 08:00:00');

/**
 * Create a run (activity + detail) for the given user with an optional TRIMP.
 *
 * @return array{0: Activity, 1: ActivityDetail}
 */
function effortRun(User $user, Carbon $when, ?float $trimp): array
{
    $activity = Activity::factory()->for($user)->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $when,
        'trimp_edwards' => $trimp,
    ]);

    return [$activity, $detail];
}

/** Seed $count prior runs, each with the same baseline TRIMP. */
function seedBaseline(User $user, Carbon $asOf, int $count, float $trimp): void
{
    for ($i = 1; $i <= $count; $i++) {
        effortRun($user, $asOf->copy()->subDays($i), $trimp);
    }
}

it('returns null when the run has no TRIMP or no start_date_local', function (?float $trimp, bool $dated) use ($asOf): void {
    $activity = Activity::factory()->for(User::factory()->create())->analyzed()->create();
    $detail = ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => $dated ? $asOf() : null,
        'trimp_edwards' => $trimp,
    ]);

    expect(app(RelativeEffort::class)->forRun($activity, $detail))->toBeNull();
})->with([
    'no TRIMP (HR-less)' => [null, true],
    'no start_date_local' => [100.0, false],
]);

it('reports the raw TRIMP with no comparison when the baseline is too thin', function () use ($asOf): void {
    $user = User::factory()->create();
    seedBaseline($user, $asOf(), 2, 100.0); // only 2 prior runs, need 3
    [$activity, $detail] = effortRun($user, $asOf(), 130.0);

    expect(app(RelativeEffort::class)->forRun($activity, $detail))->toBe([
        'trimp' => 130.0,
        'baseline' => null,
        'ratio' => null,
        'band' => null,
    ]);
});

it('compares against the 28-day baseline and bands the ratio', function () use ($asOf): void {
    $user = User::factory()->create();
    seedBaseline($user, $asOf(), 4, 100.0); // baseline avg = 100
    [$activity, $detail] = effortRun($user, $asOf(), 140.0);

    expect(app(RelativeEffort::class)->forRun($activity, $detail))->toBe([
        'trimp' => 140.0,
        'baseline' => 100,
        'ratio' => 1.4,
        'band' => RelativeEffort::WELL_ABOVE,
    ]);
});

it('bands a slightly-harder run as above', function () use ($asOf): void {
    $user = User::factory()->create();
    seedBaseline($user, $asOf(), 4, 100.0);
    [$activity, $detail] = effortRun($user, $asOf(), 115.0);

    $result = nonNull(app(RelativeEffort::class)->forRun($activity, $detail));
    expect($result['ratio'])->toBe(1.15)
        ->and($result['band'])->toBe(RelativeEffort::ABOVE);
});

it('bands a run near the baseline as typical', function () use ($asOf): void {
    $user = User::factory()->create();
    seedBaseline($user, $asOf(), 4, 100.0);
    [$activity, $detail] = effortRun($user, $asOf(), 100.0);

    expect(nonNull(app(RelativeEffort::class)->forRun($activity, $detail))['band'])->toBe(RelativeEffort::TYPICAL);
});

it('bands an easy run as below', function () use ($asOf): void {
    $user = User::factory()->create();
    seedBaseline($user, $asOf(), 4, 100.0);
    [$activity, $detail] = effortRun($user, $asOf(), 70.0);

    $result = nonNull(app(RelativeEffort::class)->forRun($activity, $detail));
    expect($result['ratio'])->toBe(0.7)
        ->and($result['band'])->toBe(RelativeEffort::BELOW);
});

it('excludes the current run from its own baseline', function () use ($asOf): void {
    $user = User::factory()->create();
    // Three prior easy runs form the baseline; the current hard run must not
    // dilute its own comparison.
    seedBaseline($user, $asOf(), 3, 100.0);
    [$activity, $detail] = effortRun($user, $asOf(), 150.0);

    $result = nonNull(app(RelativeEffort::class)->forRun($activity, $detail));
    expect($result['baseline'])->toBe(100)
        ->and($result['band'])->toBe(RelativeEffort::WELL_ABOVE);
});
