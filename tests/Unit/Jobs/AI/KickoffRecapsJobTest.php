<?php

declare(strict_types=1);

use App\Actions\AI\KickoffMonthlyRecaps;
use App\Actions\AI\KickoffWeeklyRecaps;
use App\Jobs\AI\AnalyzePlanDayVoiceJob;
use App\Jobs\AI\KickoffRecapsJob;
use App\Models\AI\Analysis;
use App\Models\PlannedSession;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\PlanNarrationRequester;
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

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class));

    expect($seen['weekly'])->toBe([$user->id, AnalysisOrigin::Ingest])
        ->and($seen['monthly'])->toBe([$user->id, AnalysisOrigin::Ingest]);
});

/** The chain's last link is the only place that can answer "is the history in yet?". */
it('stamps backfilled_at as the last link of the connect chain', function (): void {
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class));

    expect($user->fresh()->backfilled_at)->not->toBeNull();
});

it('narrates the first week when onboarding already wrote a plan', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    PlannedSession::factory()->for($user)->create(['date' => Carbon::today()->toDateString()]);
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class));

    Bus::assertDispatched(
        AnalyzePlanDayVoiceJob::class,
        fn (AnalyzePlanDayVoiceJob $job): bool => Analysis::query()->find($job->analysisId)?->subject_id === $user->id,
    );
});

/** Onboarding is still open, so there is no first week to describe yet. */
it('narrates nothing when no plan exists yet', function (): void {
    Bus::fake();
    $user = User::factory()->create();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob($user->id)->handle($weekly, $monthly, app(PlanNarrationRequester::class));

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
    expect($user->fresh()->backfilled_at)->not->toBeNull();
});

it('does nothing beyond the recaps when the user is gone', function (): void {
    Bus::fake();
    [$weekly, $monthly] = kickoffRecapsDoubles();

    new KickoffRecapsJob(404)->handle($weekly, $monthly, app(PlanNarrationRequester::class));

    Bus::assertNotDispatched(AnalyzePlanDayVoiceJob::class);
});
