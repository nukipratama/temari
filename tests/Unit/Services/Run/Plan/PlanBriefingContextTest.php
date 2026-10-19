<?php

declare(strict_types=1);

use App\Enums\SessionType;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\WeeklySnapshot;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\Run\Metrics\TrainingLoad;
use App\Services\Run\Plan\PlanBriefingContext;
use App\Services\Run\Plan\TrainingBaseline;
use App\Support\Numeric;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
});
afterEach(fn () => Carbon::setTestNow());

function briefingAthlete(): User
{
    $user = User::factory()->create();
    WeeklySnapshot::factory()->for($user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'runs' => 4,
        'distance_km' => 30.0,
    ]);

    return $user;
}

/**
 * @param  Collection<int, PlannedSession>  $rows
 * @param  array{sessions_per_week: int, weekly_volume_km: float, long_run_km: float, long_run_cap_km: float, long_run_progression_cap_km: float, self_scaled: bool}  $baseline
 */
function aheadOfPlanPct(User $user, Collection $rows, array $baseline): mixed
{
    return new PlanBriefingContext(app(HydrationBacklog::class), app(TrainingLoad::class))
        ->forUser($user, Carbon::today(), $rows, $baseline)->readinessAssessment['inputs']['ahead_of_plan_pct'];
}

it('builds the same briefing context once and hands it back for the same athlete and day', function (): void {
    $user = briefingAthlete();
    $briefing = app(PlanBriefingContext::class);
    $first = $briefing->forUser($user, Carbon::today());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $second = $briefing->forUser($user, Carbon::today());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($second)->toBe($first)
        ->and($queries)->toBe([]);
});

it('builds a fresh briefing context for another day or another athlete', function (): void {
    $user = briefingAthlete();
    $briefing = app(PlanBriefingContext::class);
    $today = $briefing->forUser($user, Carbon::today());

    expect($briefing->forUser($user, Carbon::today()->addDay()))->not->toBe($today)
        ->and($briefing->forUser(User::factory()->create(), Carbon::today()))->not->toBe($today);
});

it('is shared by every consumer within a request', function (): void {
    expect(app(PlanBriefingContext::class))->toBe(app(PlanBriefingContext::class));
});

it('sizes the prescription to date from the week rows and baseline its caller holds', function (): void {
    $user = briefingAthlete();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString(), 'session_type' => SessionType::Easy]);
    WeeklySnapshot::factory()->for($user)->create(['week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(), 'runs' => 1, 'distance_km' => 3.0]);
    $rows = PlannedSession::query()->where('user_id', $user->id)->get();
    $baseline = app(TrainingBaseline::class)->forUser($user, Carbon::today());

    expect(aheadOfPlanPct($user, $rows, $baseline))->toBe(app(PlanBriefingContext::class)->forUser($user, Carbon::today())->readinessAssessment['inputs']['ahead_of_plan_pct'])
        ->and(aheadOfPlanPct($user, $rows, [...$baseline, 'long_run_km' => $baseline['long_run_km'] / 2]))->toBeGreaterThan(Numeric::float(aheadOfPlanPct($user, $rows, $baseline)));
});
