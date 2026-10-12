<?php

declare(strict_types=1);

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Actions\AI\RequestTodaysBriefing;
use App\Enums\PlanRegenerationReason;
use App\Jobs\AI\AnalyzeBriefingMascotVoiceJob;
use App\Jobs\AI\AnalyzePlanSeasonVoiceJob;
use App\Jobs\AI\KickoffRecapsJob;
use App\Jobs\Run\RegeneratePlanJob;
use App\Jobs\Strava\HydrateBacklogForUserJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Models\StravaConnection;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisType;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\PlanNarrationRequester;
use App\Services\Run\Plan\Periodizer;
use Illuminate\Contracts\Cache\Lock as CacheLock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;

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

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    expect($seen['weekly'])->toBe([$user->id, AnalysisOrigin::Ingest])
        ->and($seen['monthly'])->toBe([$user->id, AnalysisOrigin::Ingest]);
});

/**
 * #1051: the backfill landing is the moment hydration should start, not the
 * next `strava:hydrate-backlog` tick up to 15 minutes later.
 */
it('dispatches an immediate hydration batch for the backfilled user', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    Bus::assertDispatched(fn (HydrateBacklogForUserJob $job): bool => $job->userId === $user->id);
});

it('dispatches no hydration batch and no season voice when the user is gone', function (): void {
    Bus::fake();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob(404)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    Bus::assertNotDispatched(HydrateBacklogForUserJob::class);
    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);
});

/** The chain's last link is the only place that can answer "is the history in yet?". */
it('stamps backfilled_at as the last link of the connect chain', function (): void {
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    expect($user->fresh()->backfilled_at)->not->toBeNull();
});

it('queues onboarding regeneration when the plan lock is busy', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();
    $lock = Mockery::mock(CacheLock::class);
    $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException());
    $cache = Mockery::mock(Cache::getFacadeRoot())->makePartial();
    $cache->shouldReceive('lock')
        ->once()
        ->with("plan-reconciliation:{$user->id}", 150)
        ->andReturn($lock);
    Cache::swap($cache);

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    Bus::assertDispatched(fn (RegeneratePlanJob $job): bool => $job->userId === $user->id && $job->reason === PlanRegenerationReason::Onboarding);
    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);
});

/** Narrating first would describe a season that is about to be replaced. */
it('re-sizes before narrating, so the described season is the one that stands', function (): void {
    Bus::fake();
    Carbon::setTestNow(Carbon::parse('2026-09-07'));
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    $plannedThisWeek = PlannedSession::query()
        ->where('user_id', $user->id)
        ->whereBetween('date', [Carbon::today(), Carbon::today()->endOfWeek(Carbon::SUNDAY)])
        ->pluck('date')
        ->map(fn (Carbon $date): string => $date->toDateString())
        ->all();

    // The single cold-start row is gone, replaced by a full re-sized week —
    // proof the resize ran before the season (the only thing left to narrate)
    // was requested.
    expect($plannedThisWeek)->not->toHaveCount(1);
    Bus::assertDispatched(AnalyzePlanSeasonVoiceJob::class);

    Carbon::setTestNow();
});

/** Onboarding is still open, so there is no first week to describe yet. */
it('narrates nothing and plans nothing when no plan exists yet', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    Bus::assertNotDispatched(AnalyzePlanSeasonVoiceJob::class);
    expect(PlannedSession::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($user->fresh()->backfilled_at)->not->toBeNull();
});

it('kicks every Trends range off the backfill, so a new account is not days behind', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    // The 7d range's own cron only reaches athletes who existed when it last
    // ran, so without this a Friday signup waits up to a day for its first read.
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

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    expect(Analysis::query()->where('analysis_type', AnalysisType::TrendRead)->count())->toBe(0);
});

it('defers the Trends read while the backlog is still hydrating', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    StravaConnection::factory()->for($user)->create();
    $activity = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::now()->subDay()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    expect(Analysis::query()->where('analysis_type', AnalysisType::TrendRead)->count())->toBe(0);
});

// Onboarding asked for today's briefing while the history was still importing,
// so this link asks again — the row it re-opens is the one that read nothing.
it('re-requests today\'s briefing once the backfill has landed', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class), app(AnalysisService::class), app(Periodizer::class), app(RequestTodaysBriefing::class), app(HydrationBacklog::class));

    Bus::assertDispatchedTimes(AnalyzeBriefingMascotVoiceJob::class, 1);
});
