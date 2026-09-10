<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanPageAssembler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->assembler = app(PlanPageAssembler::class);
});
afterEach(fn () => Carbon::setTestNow());

function assemblerAthlete(): User
{
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::today()->subWeeks($i)->toDateString(),
            'runs' => 4,
            'distance_km' => 30.0,
        ]);
    }

    return $user;
}

it('returns no race payload when the athlete has no active goal', function (): void {
    expect($this->assembler->race(assemblerAthlete()))->toBeNull();
});

it('names the active race and the day it is run', function (): void {
    $user = assemblerAthlete();
    RaceGoal::factory()->for($user)->create([
        'race_date' => '2026-10-31',
        'distance_m' => 10_000,
        'name' => 'City 10K',
    ]);

    expect($this->assembler->race($user))->toBe(['race_date' => '2026-10-31', 'name' => 'City 10K']);
});

it('ensures the season once even though three props ask for it', function (): void {
    $user = assemblerAthlete();
    $today = Carbon::today();

    $this->assembler->season($user, $today);
    $this->assembler->seasonSummary($user, $today);
    $this->assembler->seasonAdherencePct($user, $today);

    expect(Season::query()->where('user_id', $user->id)->count())->toBe(1);
});

it('has nothing to explain about a week the adapter never touched', function (): void {
    expect($this->assembler->adaptation(assemblerAthlete(), Carbon::today()))->toBeNull();
});

it('explains the current week in the adapter\'s own words', function (): void {
    $user = assemblerAthlete();
    PlanAdaptation::query()->create([
        'user_id' => $user->id,
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
        'reason' => AdaptationReason::MissedWeek,
        'deload' => true,
        'quality_delta' => -1,
        'adherence_pct' => 0,
    ]);

    expect($this->assembler->adaptation($user, Carbon::today()))->toBe([
        'reason' => AdaptationReason::MissedWeek->value,
        'headline' => AdaptationReason::MissedWeek->headline(),
        'detail' => AdaptationReason::MissedWeek->detail(0),
        'deload' => true,
    ]);
});

it('has no weeks to render before anything has been planned', function (): void {
    expect($this->assembler->weeks(assemblerAthlete(), Carbon::today()))->toBe([]);
});

it('renders the generated weeks with the current one marked as such', function (): void {
    $user = assemblerAthlete();
    app(Periodizer::class)->regenerate($user, Carbon::today());

    $weeks = $this->assembler->weeks($user, Carbon::today());
    $current = collect($weeks)->firstWhere('type', 'current');

    expect($weeks)->not->toBeEmpty()
        ->and($current['week_start'])->toBe(Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString())
        ->and($current['days'])->toHaveCount(7);
});

it('reports the baseline session count the plan is built on', function (): void {
    expect($this->assembler->sessionsPerWeek(assemblerAthlete(), Carbon::today()))->toBeGreaterThan(0);
});

it('asks for no cooldown when the athlete has not just replanned', function (): void {
    expect($this->assembler->regenerateCooldownSeconds(assemblerAthlete()))->toBeNull();
});

it('counts only analyzed activities toward the week already run', function (): void {
    $user = User::factory()->create();
    $from = Carbon::today()->subDays(3);
    $to = Carbon::today()->subDay();

    $analyzed = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($analyzed)->create([
        'start_date_local' => $from->copy()->addDay(),
        'distance' => 8_000.0,
    ]);
    $stub = Activity::factory()->for($user)->stub()->create();
    ActivityDetail::factory()->for($stub)->create([
        'start_date_local' => $from->copy()->addDay(),
        'distance' => 20_000.0,
    ]);

    $method = new ReflectionMethod(PlanPageAssembler::class, 'completedKmInRange');

    expect($method->invoke($this->assembler, $user, $from, $to))->toBe(8.0);
});
