<?php

declare(strict_types=1);

use App\Actions\AI\RecentlyActiveUsers;
use App\Events\RaceGoalChanged;
use App\Jobs\AI\AnalyzeTrendReadJob;
use App\Listeners\RefreshTrendReadOnRaceGoalChanged;
use App\Models\AI\Analysis;
use App\Models\RaceGoal;
use App\Models\User;
use App\Services\AI\AnalysisOrigin;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\NarrationOrigin;
use App\Services\AI\TrendReadFingerprint;
use App\Services\Run\Ingest\HydrationBacklog;
use App\Services\Run\Plan\RaceGoalService;
use App\Enums\RaceIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(fn () => Carbon::setTestNow('2026-08-19 12:00:00'));
afterEach(fn () => Carbon::setTestNow());

function raceListener(AnalysisService $service): RefreshTrendReadOnRaceGoalChanged
{
    return new RefreshTrendReadOnRaceGoalChanged($service, new RecentlyActiveUsers(), app(HydrationBacklog::class), app(TrendReadFingerprint::class));
}

function stampedTrendRead(User $user): Analysis
{
    return Analysis::factory()->done('a read')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => 'arc',
        'content_fingerprint' => app(TrendReadFingerprint::class)->forUser($user),
    ]);
}

/** @return AnalysisService&MockInterface */
function trendServiceExpecting(int $times): AnalysisService
{
    /** @var AnalysisService&MockInterface $service */
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')->times($times)->andReturn(new Analysis());

    return $service;
}

it('re-narrates a done read once, as the athlete\'s own change, when the race moved its material', function (): void {
    $user = User::factory()->seenToday()->create();
    stampedTrendRead($user);
    RaceGoal::factory()->for($user)->create();
    /** @var AnalysisService&MockInterface $service */
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->withArgs(fn (string $subjectOrType, int $subjectId, mixed $type, ?string $discriminator, ?int $delaySeconds, bool $invalidate): bool => $subjectOrType === AnalysisType::TREND_READ_SUBJECT_TYPE
            && $subjectId === $user->id
            && $type === AnalysisType::TrendRead
            && $discriminator === 'arc'
            && $invalidate
            && app(NarrationOrigin::class)->current() === AnalysisOrigin::User)
        ->andReturn(new Analysis());
    $listener = raceListener($service);

    $listener->handle(new RaceGoalChanged($user->id));

    expect($listener->debounceId(new RaceGoalChanged($user->id)))->toBe((string) $user->id);
});

it('leaves the read alone', function (Closure $arrange): void {
    $user = User::factory()->seenToday()->create();
    $arrange($user);

    raceListener(trendServiceExpecting(0))->handle(new RaceGoalChanged($user->id));
})->with([
    'when the race change left its material as it was' => [function (User $user): void {
        RaceGoal::factory()->for($user)->create();
        stampedTrendRead($user);
    }],
    'when there is no done read yet' => [fn (User $user) => RaceGoal::factory()->for($user)->create()],
    'for the demo athlete' => [function (User $user): void {
        $user->forceFill(['is_demo' => true])->save();
        stampedTrendRead($user);
        RaceGoal::factory()->for($user)->create();
    }],
    'for an athlete not seen lately' => [function (User $user): void {
        stampedTrendRead($user);
        RaceGoal::factory()->for($user)->create();
        $user->forceFill(['last_seen_at' => now()->subYear()])->save();
    }],
    'while a snapshot repair is pending' => [function (User $user): void {
        stampedTrendRead($user);
        RaceGoal::factory()->for($user)->create();
        $user->forceFill(['trend_snapshots_pending_from' => now()->subDay()])->save();
    }],
    'inside the reread cooldown' => [function (User $user): void {
        stampedTrendRead($user)->startCooldown();
        RaceGoal::factory()->for($user)->create();
    }],
]);

it('dispatches one trend read job when the athlete revises the race through the race service', function (): void {
    config(['azure_openai.uri' => 'https://example.test', 'azure_openai.api_key' => 'test-key']);
    $user = User::factory()->seenToday()->create();
    RaceGoal::factory()->for($user)->create(['race_date' => '2026-10-11', 'distance_m' => 21_097, 'goal_time_sec' => 7_200]);
    $row = stampedTrendRead($user);
    Bus::fake([AnalyzeTrendReadJob::class]);

    app(RaceGoalService::class)->submit($user, ['race_date' => '2026-10-11', 'distance_m' => 21_097, 'goal_time_sec' => 7_000], RaceIntent::Update);

    Bus::assertDispatchedTimes(AnalyzeTrendReadJob::class, 1);
    expect($row->refresh()->status)->toBe(AnalysisStatus::Queued);
});
