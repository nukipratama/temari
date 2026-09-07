<?php

declare(strict_types=1);

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\KickoffRecapsJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/** @return array{0: KickoffWeeklyRecaps, 1: KickoffMonthlyRecaps} */
function kickoffRecapsDoubles(): array
{
    $weekly = Mockery::mock(KickoffWeeklyRecaps::class);
    $weekly->shouldReceive('__invoke')->andReturn(['dispatched' => 0, 'rule_based' => 0]);

    $monthly = Mockery::mock(KickoffMonthlyRecaps::class);
    $monthly->shouldReceive('__invoke')->andReturn(['dispatched' => 0, 'rule_based' => 0]);

    return [$weekly, $monthly];
}

it('runs the weekly and monthly kickoff for its own user, attributed to the ingest cascade', function (): void {
    $user = User::factory()->create();
    $origin = app(NarrationOrigin::class);
    $seen = [];

    $weekly = Mockery::mock(KickoffWeeklyRecaps::class);
    $weekly->shouldReceive('__invoke')->once()
        ->andReturnUsing(function (?int $userId) use (&$seen, $origin): array {
            $seen['weekly'] = [$userId, $origin->current()];

            return ['dispatched' => 0, 'rule_based' => 0];
        });

    $monthly = Mockery::mock(KickoffMonthlyRecaps::class);
    $monthly->shouldReceive('__invoke')->once()
        ->andReturnUsing(function (?int $userId) use (&$seen, $origin): array {
            $seen['monthly'] = [$userId, $origin->current()];

            return ['dispatched' => 0, 'rule_based' => 0];
        });

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    expect($seen['weekly'])->toBe([$user->id, AnalysisOrigin::Ingest])
        ->and($seen['monthly'])->toBe([$user->id, AnalysisOrigin::Ingest]);
});

/** The chain's last link is the only place that can answer "is the history in yet?". */
it('stamps backfilled_at as the last link of the connect chain', function (): void {
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    expect($user->fresh()->backfilled_at)->not->toBeNull();
});

it('narrates the first week when onboarding already wrote a plan', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    Bus::assertDispatched(
        AnalyzePlanDayVoiceJob::class,
        fn (AnalyzePlanDayVoiceJob $job): bool => Analysis::query()->find($job->analysisId)?->subject_id === $user->id,
    );
});

/**
 * Onboarding writes the first plan before the backfill has finished, so it is
 * sized from cold-start seeds. This is the moment that history exists.
 */
it('re-sizes the plan against the history the backfill just landed', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    $lastPlanned = PlannedSession::query()->where('user_id', $user->id)->max('date');

    expect(Carbon::today()->diffInWeeks(Carbon::parse($lastPlanned)))
        ->toBeGreaterThan(1.0);
});

/** Narrating first would describe a week that is about to be replaced. */
it('re-sizes before narrating, so the described week is the week that stands', function (): void {
    Bus::fake();
    Carbon::setTestNow(Carbon::parse('2026-09-07'));
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    $narrated = Analysis::query()
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::PlanDayVoice)
        ->pluck('discriminator')
        ->all();

    $plannedThisWeek = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [Carbon::today(), Carbon::today()->endOfWeek(Carbon::SUNDAY)])
        ->pluck('date')
        ->map(fn (Carbon $date): string => $date->toDateString())
        ->all();

    expect($narrated)->toEqualCanonicalizing($plannedThisWeek)
        ->and($plannedThisWeek)->not->toHaveCount(1);

    Carbon::setTestNow();
});

/** Onboarding is still open, so there is no first week to describe yet. */
it('narrates nothing and plans nothing when no plan exists yet', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    expect(PlannedSession::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->backfilled_at)->not->toBeNull();
});

it('does nothing beyond the recaps when the user is gone', function (): void {
    Bus::fake();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob(404)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
});

it('kicks every Trends range off the backfill, so a new account is not days behind', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    // Each range refreshes on its own cadence and every cron only reaches
    // athletes who existed when it last ran, so without this a Friday signup
    // waits up to three days for 90d and seven for 12mo.
    $ranges = Analysis::query()
        ->where('subject_id', $user->id)
        ->where('analysis_type', AnalysisType::TrendRead)
        ->pluck('discriminator')
        ->all();

    expect($ranges)->toEqualCanonicalizing(AnalysisType::TREND_READ_RANGES);
});

it('reads no trends for an athlete whose backfill found no runs', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class));

    expect(Analysis::query()->where('analysis_type', AnalysisType::TrendRead)->count())->toBe(0);
});
