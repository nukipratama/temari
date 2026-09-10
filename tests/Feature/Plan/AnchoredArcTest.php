<?php

declare(strict_types=1);

use App\Enums\ExperienceLevel;
use App\Enums\PlanPhase;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\CurrentWeekPlanBuilder;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\TrainingBaseline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

afterEach(fn () => Carbon::setTestNow());

const ARC_START = '2026-09-07';

function logWeek(User $user, string $weekEnding, float $km): void
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
 * A real prod athlete (2026-09-08 snapshot): experienced, four sessions a
 * week on Mon/Wed/Fri with a Saturday long run, chasing a 10K on 2026-10-31.
 * The trailing weeks below trim to a 26.05 km/week mean.
 */
function anchoredArcAthlete(): User
{
    $user = User::factory()->create();

    $trailing = [
        '2026-09-06' => 25.5,
        '2026-08-30' => 23.6,
        '2026-08-23' => 20.4,
        '2026-08-16' => 44.7,
        '2026-08-09' => 28.1,
        '2026-08-02' => 27.0,
        '2026-07-26' => 28.5,
    ];
    foreach ($trailing as $weekEnding => $km) {
        logWeek($user, $weekEnding, $km);
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
        'distance_m' => 10_000,
        'goal_time_sec' => 3_540,
    ]);

    return $user;
}

/**
 * Walks the arc a Monday at a time the way production does — regenerate,
 * then read back the week the athlete is actually being asked to train,
 * logging whatever they then went and ran.
 *
 * @param  list<int>  $missedWeekIndexes  weeks the athlete logs nothing for
 * @return list<array{phase: PlanPhase, multiplier: float, planned_km: float, anchor_km: float}>
 */
function trainedArc(User $user, int $weeks, array $missedWeekIndexes = []): array
{
    $periodizer = app(Periodizer::class);
    $currentWeek = app(CurrentWeekPlanBuilder::class);
    $baseline = app(TrainingBaseline::class);

    $trained = [];
    for ($i = 0; $i < $weeks; $i++) {
        $monday = Carbon::parse(ARC_START)->addWeeks($i);
        Carbon::setTestNow($monday->copy()->setTime(8, 0));

        $periodizer->regenerate($user, $monday->copy());

        $mondaySession = PlannedSession::query()
            ->where('user_id', $user->id)
            ->where('date', $monday->toDateString())
            ->firstOrFail();

        $trained[] = [
            'phase' => $mondaySession->phase,
            'multiplier' => round((float) $mondaySession->volume_multiplier, 3),
            'planned_km' => $currentWeek->forUser($user, $monday->copy())['planned_km_this_week'],
            'anchor_km' => round($baseline->forUser($user, $monday->copy())['weekly_volume_km'], 2),
        ];

        logWeek(
            $user,
            $monday->copy()->addDays(6)->toDateString(),
            in_array($i, $missedWeekIndexes, true) ? 0.0 : 26.0,
        );
    }

    return $trained;
}

it('prescribes the deload week well under the build weeks around it', function (): void {
    $arc = trainedArc(anchoredArcAthlete(), 8);

    expect($arc[3]['planned_km'])->toBeLessThan($arc[2]['planned_km'] * 0.75)
        ->and($arc[4]['planned_km'])->toBeGreaterThan($arc[2]['planned_km']);
});

it('holds the arc\'s starting volume for its whole length', function (): void {
    $arc = trainedArc(anchoredArcAthlete(), 8);

    expect(array_unique(array_column($arc, 'anchor_km')))->toBe([26.05]);
});

it('does not lower the next week\'s prescription because a build week was missed', function (): void {
    $intact = trainedArc(anchoredArcAthlete(), 6);
    $missed = trainedArc(anchoredArcAthlete(), 6, missedWeekIndexes: [4]);

    expect($missed[5]['planned_km'])->toBe($intact[5]['planned_km'])
        ->and($missed[5]['anchor_km'])->toBe($intact[5]['anchor_km']);
});

it('re-anchors the arc only once the athlete\'s own volume has collapsed away from it', function (): void {
    $user = anchoredArcAthlete();
    $periodizer = app(Periodizer::class);
    $periodizer->regenerate($user, Carbon::parse(ARC_START));

    $anchor = Season::query()->where('user_id', $user->id)->value('anchor_weekly_volume_km');
    expect(round((float) $anchor, 2))->toBe(26.05);

    // Four weeks of nothing run at all: a trimmed six-week mean this far
    // below the anchor is a layoff, not a down week.
    foreach (range(1, 4) as $i) {
        logWeek($user, Carbon::parse(ARC_START)->addWeeks($i)->addDays(6)->toDateString(), 0.0);
    }
    $laterMonday = Carbon::parse(ARC_START)->addWeeks(5);
    Carbon::setTestNow($laterMonday->copy()->setTime(8, 0));
    $periodizer->regenerate($user, $laterMonday->copy());

    expect((float) Season::query()->where('user_id', $user->id)->value('anchor_weekly_volume_km'))
        ->toBeLessThan(26.05 * 0.75);
});

it('never re-anchors upward, so the ramp cannot compound on its own output', function (): void {
    $user = anchoredArcAthlete();
    $periodizer = app(Periodizer::class);
    $periodizer->regenerate($user, Carbon::parse(ARC_START));

    foreach (range(1, 5) as $i) {
        logWeek($user, Carbon::parse(ARC_START)->addWeeks($i)->addDays(6)->toDateString(), 60.0);
    }
    $laterMonday = Carbon::parse(ARC_START)->addWeeks(6);
    Carbon::setTestNow($laterMonday->copy()->setTime(8, 0));
    $periodizer->regenerate($user, $laterMonday->copy());

    expect(round((float) Season::query()->where('user_id', $user->id)->value('anchor_weekly_volume_km'), 2))
        ->toBe(26.05);
});
