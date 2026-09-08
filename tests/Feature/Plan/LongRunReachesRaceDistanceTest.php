<?php

declare(strict_types=1);

use App\Enums\ExperienceLevel;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

const FLOOR_ARC_START = '2026-09-07';

function logFloorWeek(User $user, string $weekEnding, float $km): void
{
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => $weekEnding,
        'distance_km' => $km,
        'runs' => 4,
        'moving_time_sec' => (int) round($km * 360),
        'weekly_trimp' => 300.0,
        'atl_7d' => 70.0,
        'ctl_42d' => 75.0,
        'form' => 5.0,
        'form_status' => 'optimal',
        'monotony' => 1.1,
        'strain' => 330.0,
        'avg_decoupling' => 2.0,
    ]);
}

/**
 * The same prod athlete the anchored arc is simulated against: 26.05 km/week
 * trimmed mean, four sessions, chasing a 10K. A 35% volume share puts their
 * long run at 9.1 km — they would reach the start line having never run the
 * distance.
 */
function longRunFloorAthlete(float $goalDistanceM = 10_000): User
{
    $user = User::factory()->create();

    foreach (['2026-09-06' => 25.5, '2026-08-30' => 23.6, '2026-08-23' => 20.4, '2026-08-16' => 44.7, '2026-08-09' => 28.1, '2026-08-02' => 27.0] as $weekEnding => $km) {
        logFloorWeek($user, $weekEnding, $km);
    }

    TrainingPreference::query()->create([
        'user_id' => $user->id,
        'experience_level' => ExperienceLevel::Experienced,
        'sessions_per_week' => 4,
        'run_days' => [1, 3, 5, 6],
        'long_run_day' => 6,
    ]);

    RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-10-31',
        'distance_m' => (int) $goalDistanceM,
        'goal_time_sec' => 3_540,
    ]);

    return $user;
}

/**
 * @return list<array{phase: PlanPhase, long_km: float, week_km: float}>
 */
function longRunSeries(User $user, int $weeks): array
{
    $periodizer = app(Periodizer::class);
    $currentWeek = app(CurrentWeekPlanBuilder::class);

    $series = [];
    for ($i = 0; $i < $weeks; $i++) {
        $monday = Carbon::parse(FLOOR_ARC_START)->addWeeks($i);
        Carbon::setTestNow($monday->copy()->setTime(8, 0));
        $periodizer->regenerate($user, $monday->copy());

        $week = $currentWeek->forUser($user, $monday->copy());
        $longDay = collect($week['days'])->firstWhere('session_type', SessionType::Long->value);

        $series[] = [
            'phase' => PlannedSession::query()
                ->where('user_id', $user->id)
                ->where('date', $monday->toDateString())
                ->value('phase'),
            'long_km' => (float) ($longDay['distance_km'] ?? 0.0),
            'week_km' => $week['planned_km_this_week'],
        ];

        logFloorWeek($user, $monday->copy()->addDays(6)->toDateString(), 26.0);
    }

    return $series;
}

it('works the long run up to the race distance before the taper', function (): void {
    $series = longRunSeries(longRunFloorAthlete(), 8);

    $beforeTaper = array_filter($series, fn (array $week): bool => $week['phase'] !== PlanPhase::Taper);

    expect(max(array_column($beforeTaper, 'long_km')))->toBeGreaterThanOrEqual(10.0);
});

it('reaches it by ramping rather than by jumping there in week one', function (): void {
    $series = longRunSeries(longRunFloorAthlete(), 8);

    // 9.1 km was the un-floored share of this athlete's week. The floor lifts
    // the arc's STARTING long run only as far as the build ramp needs to carry
    // it to 10 km, not to 10 km outright.
    expect($series[0]['long_km'])->toBeLessThan(9.7)
        ->and($series[0]['long_km'])->toBeGreaterThan(9.1);
});

it('never lets the long run take more than half the week', function (): void {
    $series = longRunSeries(longRunFloorAthlete(), 8);

    foreach ($series as $week) {
        expect($week['long_km'])->toBeLessThanOrEqual($week['week_km'] * 0.5);
    }
});

it('leaves a marathon goal on its own coaching, well under race distance', function (): void {
    $series = longRunSeries(longRunFloorAthlete(goalDistanceM: 42_195), 8);

    expect(max(array_column($series, 'long_km')))->toBeLessThan(42.195);
});
