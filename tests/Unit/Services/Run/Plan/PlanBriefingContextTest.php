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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Carbon::setTestNow('2026-08-10 08:00:00');
    $this->user = User::factory()->create();
    WeeklySnapshot::factory()->for($this->user)->create([
        'week_ending' => Carbon::today()->toDateString(),
        'runs' => 4,
        'distance_km' => 30.0,
    ]);
});
afterEach(fn () => Carbon::setTestNow());

it('builds the same briefing context once and hands it back for the same athlete and day', function (): void {
    $briefing = app(PlanBriefingContext::class);
    $first = $briefing->forUser($this->user, Carbon::today());

    DB::flushQueryLog();
    DB::enableQueryLog();
    $second = $briefing->forUser($this->user, Carbon::today());
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($second)->toBe($first)
        ->and($queries)->toBe([]);
});

it('builds a fresh briefing context for another day or another athlete', function (): void {
    $briefing = app(PlanBriefingContext::class);
    $today = $briefing->forUser($this->user, Carbon::today());

    expect($briefing->forUser($this->user, Carbon::today()->addDay()))->not->toBe($today)
        ->and($briefing->forUser(User::factory()->create(), Carbon::today()))->not->toBe($today);
});

it('is shared by every consumer within a request', function (): void {
    expect(app(PlanBriefingContext::class))->toBe(app(PlanBriefingContext::class));
});

it('sizes the prescription to date from the week rows and baseline its caller holds', function (): void {
    PlannedSession::factory()->for($this->user)->create(['date' => Carbon::today()->toDateString(), 'session_type' => SessionType::Easy]);
    WeeklySnapshot::factory()->for($this->user)->create(['week_ending' => Carbon::today()->endOfWeek(Carbon::SUNDAY)->toDateString(), 'runs' => 1, 'distance_km' => 3.0]);
    $rows = PlannedSession::query()->where('user_id', $this->user->id)->get();
    $baseline = app(TrainingBaseline::class)->forUser($this->user, Carbon::today());
    $aheadOf = fn (array $baseline): mixed => new PlanBriefingContext(app(HydrationBacklog::class), app(TrainingLoad::class))
        ->forUser($this->user, Carbon::today(), $rows, $baseline)->readinessAssessment['inputs']['ahead_of_plan_pct'];

    expect($aheadOf($baseline))->toBe(app(PlanBriefingContext::class)->forUser($this->user, Carbon::today())->readinessAssessment['inputs']['ahead_of_plan_pct'])
        ->and($aheadOf([...$baseline, 'long_run_km' => $baseline['long_run_km'] / 2]))->toBeGreaterThan($aheadOf($baseline));
});
