<?php

declare(strict_types=1);

use App\Enums\PlanPhase;
use App\Enums\RaceIntent;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlannedSession;
use App\Models\Season;
use App\Models\TrainingPreference;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\RaceGoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-10-05 08:00:00');
    $this->user = User::factory()->create();
    foreach (range(1, 6) as $weeksAgo) {
        WeeklySnapshot::factory()->for($this->user)->create([
            'week_ending' => Carbon::today()->subWeeks($weeksAgo)->endOfWeek(Carbon::SUNDAY)->toDateString(),
            'runs' => 4,
            'distance_km' => 40.0,
        ]);
    }
    ActivityDetail::factory()->for(Activity::factory()->for($this->user)->analyzed()->create())->create(['start_date_local' => '2026-10-03 07:00:00', 'distance' => 16_000]);
    TrainingPreference::factory()->for($this->user)->create(['sessions_per_week' => 4]);
});
afterEach(fn () => Carbon::setTestNow());

function currentWeekKm(User $user): float
{
    $current = collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))->firstWhere('type', 'current');

    return (float) collect($current['days'])->sum(fn (array $day): float => (float) ($day['distance_km'] ?? 0));
}

it('keeps the season and asks for no catch-up when a race is postponed out of its taper', function (): void {
    $races = app(RaceGoalService::class);
    $season = Season::factory()->for($this->user)->create([
        'starts_at' => '2026-06-08', 'ends_at' => '2026-10-10', 'anchor_weekly_volume_km' => 40.0, 'volume_floor_km' => 40.0,
    ]);
    $race = $races->submit($this->user, ['race_date' => '2026-10-10', 'distance_m' => 10_000, 'goal_time_sec' => 3000, 'name' => null], RaceIntent::Update);
    $season->update(['race_goal_id' => $race->id]);

    app(Periodizer::class)->regenerate($this->user, Carbon::today());
    $taper = PlannedSession::query()->where('user_id', $this->user->id)->whereDate('date', '2026-10-05')->firstOrFail();
    $taperKm = currentWeekKm($this->user);

    $races->submit($this->user, ['race_date' => '2026-11-07', 'distance_m' => 10_000, 'goal_time_sec' => 3000, 'name' => null], RaceIntent::Update);
    app(Periodizer::class)->regenerate($this->user, Carbon::today());
    $resumed = PlannedSession::query()->where('user_id', $this->user->id)->whereDate('date', '2026-10-05')->firstOrFail();

    expect($taper->phase)->toBe(PlanPhase::Taper)
        ->and($resumed->phase)->not->toBe(PlanPhase::Taper)
        ->and(Season::query()->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($season->fresh()->ends_at->toDateString())->toBe('2026-11-07')
        ->and(currentWeekKm($this->user))->toBeGreaterThan($taperKm)
        ->and(currentWeekKm($this->user))->toBeLessThanOrEqual(40.0 * 1.10);
});

function planTaperThenMoveRace(User $user, bool $viaRevision = true): array
{
    $races = app(RaceGoalService::class);
    $season = Season::factory()->for($user)->create([
        'starts_at' => '2026-06-08', 'ends_at' => '2026-11-07', 'anchor_weekly_volume_km' => 40.0, 'volume_floor_km' => 40.0,
    ]);
    $race = $races->submit($user, ['race_date' => '2026-10-10', 'distance_m' => 10_000, 'goal_time_sec' => 3000, 'name' => null], RaceIntent::Update);
    $season->update(['race_goal_id' => $race->id]);
    app(Periodizer::class)->regenerate($user, Carbon::today());
    $taperKm = currentWeekKm($user);

    if ($viaRevision) {
        $races->submit($user, ['race_date' => '2026-10-31', 'distance_m' => 10_000, 'goal_time_sec' => 3000, 'name' => null], RaceIntent::Update);
    } else {
        $race->update(['race_date' => '2026-10-31']);
    }
    app(Periodizer::class)->regenerate($user, Carbon::today());

    return [$taperKm, currentWeekKm($user)];
}

function weekKm(User $user, string $weekStart): float
{
    $week = collect(app(PlanPageAssembler::class)->weeks($user, Carbon::today()))->firstWhere('week_start', $weekStart);

    return (float) collect($week['days'])->sum(fn (array $day): float => (float) ($day['distance_km'] ?? 0));
}

it('resumes a postponed taper no higher than 10% above the recent actual weeks', function (): void {
    [$taperKm, $resumedKm] = planTaperThenMoveRace($this->user);

    expect($taperKm)->toBeLessThan(25.0)
        ->and($resumedKm)->toBeGreaterThan($taperKm)
        ->and($resumedKm)->toBeLessThanOrEqual(40.0 * 1.10);
});

it('ramps each later week by no more than 10% until the arc value is reached', function (): void {
    planTaperThenMoveRace($this->user);

    $first = weekKm($this->user, '2026-10-05');
    $second = weekKm($this->user, '2026-10-12');

    expect($second)->toBeGreaterThan($first)
        ->and($second)->toBeLessThanOrEqual($first * 1.10 + 0.1);
});

it('leaves a plan regenerated without a recent revision as the arc says', function (): void {
    [, $uncapped] = planTaperThenMoveRace($this->user, viaRevision: false);

    expect($uncapped)->toBeGreaterThan(40.0 * 1.10);
});

it('does not clamp a revision when there is too little recent history to bound it', function (): void {
    WeeklySnapshot::query()->where('user_id', $this->user->id)->orderBy('week_ending')->limit(5)->delete();

    [, $resumedKm] = planTaperThenMoveRace($this->user);

    expect($resumedKm)->toBeGreaterThan(40.0 * 1.10);
});
