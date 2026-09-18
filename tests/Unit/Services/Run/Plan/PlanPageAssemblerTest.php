<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\PlanAdaptation;
use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\RaceGoal;
use App\Models\Season;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\Periodizer;
use App\Services\Run\Plan\PlanPageAssembler;
use App\Services\Run\Plan\PlanRenderer;
use App\Services\Run\Plan\TrainingBaseline;
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

it('carries the under-ready line on the first Plan visit of a short block, and not after', function (): void {
    $user = assemblerAthlete();
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-26', 'distance_m' => 10_000]);
    $today = Carbon::today();

    expect($this->assembler->season($user, $today)['under_ready_line'])
        ->toBe("Twelve weeks is tighter than I'd pick for this one, so we build what we can and race what we've built.")
        ->and($this->assembler->season($user, $today)['under_ready_line'])->toBeNull();
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

it('says so when the deload takes the week under the season\'s volume floor', function (): void {
    $user = assemblerAthlete();
    PlanAdaptation::query()->create([
        'user_id' => $user->id,
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
        'reason' => AdaptationReason::LowReadiness,
        'deload' => true,
        'quality_delta' => 0,
        'adherence_pct' => 100,
        'volume_floor_km' => 25.91,
    ]);

    expect($this->assembler->adaptation($user, Carbon::today())['detail'])
        ->toBe(AdaptationReason::LowReadiness->detail(100).' that puts it under your usual 25.9 km a week, on purpose.');
});

it('says the build is waiting when the block holds its increases for unscored load', function (): void {
    $user = assemblerAthlete();
    PlanAdaptation::query()->create([
        'user_id' => $user->id,
        'week_start' => Carbon::today()->startOfWeek(Carbon::MONDAY)->toDateString(),
        'reason' => AdaptationReason::Steady,
        'deload' => false,
        'quality_delta' => 0,
        'adherence_pct' => 100,
        'increases_held' => true,
    ]);

    expect($this->assembler->adaptation($user, Carbon::today())['detail'])
        ->toBe(AdaptationReason::Steady->detail(100).' the build and the longer long runs wait until your recent runs are scored.');
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

/**
 * The real case: a tempo day eased to easy at 00:01 with its distance held.
 * The Plan row headlines the easy run the athlete was told to do.
 */
it('headlines a tempo day eased to easy on the Plan row, with tempo only as context', function (): void {
    $user = assemblerAthlete();
    $row = PlannedSession::factory()->for($user)->create([
        'date' => Carbon::today()->toDateString(),
        'session_type' => SessionType::Tempo,
    ]);
    $baseline = app(TrainingBaseline::class)->forUser($user, Carbon::today());
    $storedKm = PlanRenderer::coreKmForSession($row, $baseline['long_run_km'], $baseline['long_run_cap_km'], $baseline['self_scaled']);
    $row->update(['clamped_km' => $storedKm]);

    $day = collect($this->assembler->weeks($user, Carbon::today()))
        ->firstWhere('type', 'current')['days'][0];

    expect($day['session_type'])->toBe('easy')
        ->and($day['distance_km'])->toBe($storedKm)
        ->and($day['clamp'])->toBeNull()
        ->and($day['eased_from']['session_type'])->toBe('tempo')
        ->and($day['eased_from']['distance_km'])->toBeNull()
        ->and($day['eased_from']['voice'])->not->toBeNull();
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

/**
 * A plan generated mid-week only stores rows from that day on, so its week
 * target sums three days while the completed figure was subtracted from the
 * calendar Monday — everything the athlete ran before the plan existed was
 * deducted from a target that never included it. The measured result was an
 * easy day of 1 km and a long run of 1.6.
 */
it('measures the week already run from the first planned day, not the calendar Monday', function (): void {
    Carbon::setTestNow('2026-08-14 08:00:00'); // Friday
    $user = assemblerAthlete();

    foreach ([['2026-08-14', SessionType::Easy], ['2026-08-15', SessionType::Tempo], ['2026-08-16', SessionType::Long]] as [$date, $type]) {
        PlannedSession::factory()->for($user)->create([
            'date' => $date,
            'session_type' => $type,
            'volume_multiplier' => 1.0,
        ]);
    }

    // Monday to Thursday, before the plan existed.
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'start_date_local' => Carbon::parse('2026-08-11 07:00'),
        'distance' => 12_400.0,
    ]);

    $days = collect($this->assembler->weeks($user, Carbon::today()))
        ->firstOrFail(fn (array $week): bool => $week['type'] === 'current')['days'];

    $sunday = collect($days)->firstOrFail(fn (array $day): bool => $day['date'] === '2026-08-16');

    expect($sunday['distance_km'])->toBe($sunday['asked_km']);
});
