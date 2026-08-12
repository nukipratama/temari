<?php

declare(strict_types=1);

use App\Enums\AdaptationReason;
use App\Enums\DistanceBand;
use App\Enums\PlanPhase;
use App\Enums\SessionType;
use App\Models\PlanAdaptation;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

const LAST_MONDAY = '2026-08-03';

const THIS_MONDAY = '2026-08-10';

afterEach(fn () => Carbon::setTestNow());

function athleteWithFourWeekBaseline(): User
{
    $user = User::factory()->create();
    foreach (range(0, 3) as $i) {
        WeeklySnapshot::factory()->for($user)->create([
            'week_ending' => Carbon::parse(LAST_MONDAY)->subWeeks($i)->toDateString(),
            'runs' => 4,
            'distance_km' => 30.0,
        ]);
    }

    return $user;
}

/**
 * @return Collection<int, PlannedSession>
 */
function weekOf(User $user, string $monday): Collection
{
    return PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [$monday, Carbon::parse($monday)->addDays(6)->toDateString()])
        ->orderBy('date')
        ->get();
}

it('turns the week after a fully missed one into a real deload, not a catch-up', function (): void {
    $user = athleteWithFourWeekBaseline();
    $periodizer = app(Periodizer::class);

    Carbon::setTestNow(LAST_MONDAY.' 08:00:00');
    $periodizer->regenerate($user, Carbon::parse(LAST_MONDAY));

    $missedWeek = weekOf($user, LAST_MONDAY);
    $plannedSessions = $missedWeek->reject(fn (PlannedSession $s): bool => $s->session_type === SessionType::Rest);

    expect($plannedSessions)->toHaveCount(4)
        ->and($missedWeek->pluck('phase')->unique()->all())->toBe([PlanPhase::Build]);

    // Nothing is logged against that week: every planned session is missed.
    Carbon::setTestNow(THIS_MONDAY.' 08:00:00');
    $periodizer->regenerate($user, Carbon::parse(THIS_MONDAY));

    $adaptation = PlanAdaptation::query()
        ->where('user_id', $user->id)
        ->where('week_start', THIS_MONDAY)
        ->firstOrFail();

    expect($adaptation->reason)->toBe(AdaptationReason::MissedWeek)
        ->and($adaptation->deload)->toBeTrue()
        ->and($adaptation->adherence_pct)->toBe(0);

    $thisWeek = weekOf($user, THIS_MONDAY);

    expect($thisWeek->pluck('phase')->unique()->all())->toBe([PlanPhase::Deload])
        ->and($thisWeek->pluck('session_type')->all())->not->toContain(SessionType::Tempo)
        ->and($thisWeek->pluck('session_type')->all())->not->toContain(SessionType::Interval);
});

it('prescribes fewer km after a missed week than the build week it replaced', function (): void {
    $user = athleteWithFourWeekBaseline();
    $periodizer = app(Periodizer::class);

    Carbon::setTestNow(LAST_MONDAY.' 08:00:00');
    $periodizer->regenerate($user, Carbon::parse(LAST_MONDAY));

    $buildWeekKm = $this->actingAs($user)->get('/plan')->viewData('page')['props']['weeks'];
    $buildWeekKm = collect($buildWeekKm)->firstWhere('week_start', LAST_MONDAY)['days'];
    $buildWeekKm = collect($buildWeekKm)->sum('distance_km');

    Carbon::setTestNow(THIS_MONDAY.' 08:00:00');
    $periodizer->regenerate($user, Carbon::parse(THIS_MONDAY));

    $deloadWeekKm = $this->actingAs($user)->get('/plan')->viewData('page')['props']['weeks'];
    $deloadWeekKm = collect($deloadWeekKm)->firstWhere('week_start', THIS_MONDAY)['days'];
    $deloadWeekKm = collect($deloadWeekKm)->sum('distance_km');

    expect($deloadWeekKm)->toBeLessThan($buildWeekKm)
        ->and($deloadWeekKm)->toBeGreaterThan(0.0);
});

it('explains the deload on the Plan tab, alongside the standing disclaimer', function (): void {
    $user = athleteWithFourWeekBaseline();
    $periodizer = app(Periodizer::class);

    Carbon::setTestNow(LAST_MONDAY.' 08:00:00');
    $periodizer->regenerate($user, Carbon::parse(LAST_MONDAY));
    Carbon::setTestNow(THIS_MONDAY.' 08:00:00');
    $periodizer->regenerate($user, Carbon::parse(THIS_MONDAY));

    $this->actingAs($user)->get('/plan')
        ->assertSuccessful()
        ->assertInertia(fn (Assert $page) => $page
            ->where('adaptation.reason', AdaptationReason::MissedWeek->value)
            ->where('adaptation.deload', true)
            ->where('adaptation.headline', 'deload week')
            ->where('adaptation.detail', "you finished 0% of last week's sessions. this week comes back smaller, not doubled.")
            ->etc());
});

it('marks last week\'s untouched sessions as missed on the Plan tab', function (): void {
    $user = athleteWithFourWeekBaseline();
    $periodizer = app(Periodizer::class);

    Carbon::setTestNow(LAST_MONDAY.' 08:00:00');
    $periodizer->regenerate($user, Carbon::parse(LAST_MONDAY));
    Carbon::setTestNow(THIS_MONDAY.' 08:00:00');

    $weeks = $this->actingAs($user)->get('/plan')->viewData('page')['props']['weeks'];
    $days = collect(collect($weeks)->firstWhere('week_start', LAST_MONDAY)['days']);

    expect($days->where('session_type', '!=', 'rest')->pluck('status')->unique()->all())->toBe(['missed']);
});

it('redistributes a half-missed week into the days that remain, up to the cap', function (): void {
    $user = athleteWithFourWeekBaseline();

    Carbon::setTestNow(THIS_MONDAY.' 08:00:00');
    app(Periodizer::class)->regenerate($user, Carbon::parse(THIS_MONDAY));

    $saturday = Carbon::parse(THIS_MONDAY)->addDays(5)->toDateString();
    $sunday = Carbon::parse(THIS_MONDAY)->addDays(6)->toDateString();
    $generated = weekOf($user, THIS_MONDAY)->keyBy(fn (PlannedSession $s): string => $s->date->toDateString());

    expect($generated[$saturday]->distance_band)->toBe(DistanceBand::Short)
        ->and($generated[$sunday]->distance_band)->toBe(DistanceBand::Long);

    // Thursday, with Tuesday's and Thursday's sessions un-run.
    Carbon::setTestNow(Carbon::parse(THIS_MONDAY)->addDays(3)->setTime(18, 0));

    $weeks = $this->actingAs($user)->get('/plan')->viewData('page')['props']['weeks'];
    $days = collect(collect($weeks)->firstWhere('week_start', THIS_MONDAY)['days'])->keyBy('date');

    expect($days[$saturday]['distance_band'])->toBe(DistanceBand::Medium->value)
        ->and($days[$sunday]['distance_band'])->toBe(DistanceBand::Long->value);
});
