<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\Run\Metrics\RunHardMinutes;
use App\Services\Run\Metrics\TrainingPaceCalculator;
use App\Services\Run\Metrics\VdotEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

function runHardMinutesWithCeiling(?int $marathonSecPerKm, ?Carbon &$estimatedAsOf = null): RunHardMinutes
{
    /** @var VdotEstimator&MockInterface $vdot */
    $vdot = Mockery::mock(VdotEstimator::class);
    $vdot->shouldReceive('estimate')->andReturnUsing(function (User $user, Carbon $asOf) use (&$estimatedAsOf, $marathonSecPerKm): ?array {
        $estimatedAsOf = $asOf;

        return $marathonSecPerKm === null ? null : ['vdot' => 45.0];
    });

    /** @var TrainingPaceCalculator&MockInterface $paces */
    $paces = Mockery::mock(TrainingPaceCalculator::class);
    $paces->shouldReceive('fromVdotResult')->andReturn(
        $marathonSecPerKm === null ? null : ['easy' => 400, 'marathon' => $marathonSecPerKm, 'threshold' => 292, 'interval' => 268],
    );

    return new RunHardMinutes($vdot, $paces);
}

/**
 * @param  array<string, mixed>  $streamSummary
 */
function hotEasyRun(User $user, string $startedAt, array $streamSummary): ActivityDetail
{
    return ActivityDetail::factory()->for(Activity::factory()->for($user)->analyzed())->create([
        'start_date_local' => $startedAt,
        'stream_summary' => $streamSummary,
    ]);
}

it('counts the run against the easy ceiling', function (): void {
    Carbon::setTestNow('2026-11-07 12:00:00');
    $user = User::factory()->create();
    $detail = hotEasyRun($user, '2026-11-05 06:00:00', [
        'time_in_zone_min' => ['Z2' => 10, 'Z3' => 31.5],
        'high_hr_sec_by_gap_pace' => [300 => 120, 420 => 1770],
        'per_km' => [['km' => 1, 'pace' => '7:00', 'elapsed_sec' => 420, 'high_hr_sec_by_gap_pace' => [300 => 120, 420 => 200]]],
    ]);

    expect(runHardMinutesWithCeiling(330)->for($user, $detail))->toBe([
        'minutes' => 2.0,
        'hr_only_minutes' => 31.5,
        'easy_ceiling_sec_per_km' => 330,
        'per_km' => [['km' => 1, 'easy_sec' => 100, 'high_hr_easy_pace_sec' => 200, 'hard_sec' => 120]],
    ]);
});

it('reads a past week\'s run with the fitness of that week\'s end, not today\'s', function (): void {
    Carbon::setTestNow('2026-11-07 12:00:00');
    $user = User::factory()->create();
    $detail = hotEasyRun($user, '2026-10-21 06:00:00', ['time_in_zone_min' => ['Z3' => 12.0]]);

    $estimatedAsOf = null;
    $reading = nonNull(runHardMinutesWithCeiling(330, $estimatedAsOf)->for($user, $detail));

    expect($estimatedAsOf?->toDateString())->toBe('2026-10-25')
        ->and($reading['per_km'])->toBeNull()
        ->and($reading['minutes'])->toBe(12.0);
});

it('lets heart rate alone count without an easy ceiling, and reads nothing without zones', function (): void {
    Carbon::setTestNow('2026-11-07 12:00:00');
    $user = User::factory()->create();
    $paced = hotEasyRun($user, '2026-11-05 06:00:00', [
        'time_in_zone_min' => ['Z3' => 31.5],
        'high_hr_sec_by_gap_pace' => [420 => 1890],
    ]);
    $noHeartRate = hotEasyRun($user, '2026-11-06 06:00:00', ['per_km' => [['km' => 1, 'pace' => '7:00']]]);

    expect(runHardMinutesWithCeiling(null)->for($user, $paced)['minutes'] ?? null)->toBe(31.5)
        ->and(runHardMinutesWithCeiling(330)->for($user, $noHeartRate))->toBeNull();
});
