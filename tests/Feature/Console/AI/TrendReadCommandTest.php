<?php

declare(strict_types=1);

use App\Jobs\AI\AnalyzeTrendReadJob;
use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\StravaConnection;
use App\Models\User;
use App\Services\AI\Agent\Tools\TrendRangeTool;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use App\Services\AI\MaterialFingerprint;
use App\Services\Run\Metrics\TrainingLoad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

/** @return array<string, mixed> */
function currentTrendTotals(User $user, string $range): array
{
    return new TrendRangeTool($user, $range, new TrainingLoad())->handle([]);
}

it('dispatches the 7d range for every active user', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->seenToday()->create();

    $requestCalls = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null) use (&$requestCalls): Analysis {
            $requestCalls[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator');

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '7d'])
        ->expectsOutputToContain('Dispatched trend read (7d) for 1 active users.')
        ->assertSuccessful();

    expect($requestCalls)->toHaveCount(1)
        ->and($requestCalls[0]['subjectOrType'])->toBe(AnalysisType::TREND_READ_SUBJECT_TYPE)
        ->and($requestCalls[0]['subjectId'])->toBe($user->id)
        ->and($requestCalls[0]['type'])->toBe(AnalysisType::TrendRead)
        ->and($requestCalls[0]['discriminator'])->toBe('7d');

    Carbon::setTestNow();
});

it('holds the trend read for an athlete whose backlog is still hydrating', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->seenToday()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subHour()]);
    $stub = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($stub)->create(['start_date_local' => Carbon::now()->subDay()]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '7d'])
        ->expectsOutputToContain('Dispatched trend read (7d) for 1 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('does not hold the trend read past the hydration grace window', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->seenToday()->create();
    StravaConnection::factory()->for($user)->create(['created_at' => Carbon::now()->subDays(90)]);
    $stub = Activity::factory()->for($user)->summaryOnly()->create();
    ActivityDetail::factory()->for($stub)->create(['start_date_local' => Carbon::now()->subDays(80)]);

    $requestCalls = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')->once()->andReturnUsing(function () use (&$requestCalls): Analysis {
        $requestCalls[] = true;

        return new Analysis();
    });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '7d'])->assertSuccessful();

    expect($requestCalls)->toHaveCount(1);

    Carbon::setTestNow();
});

it('rejects a range outside AnalysisType::TREND_READ_RANGES', function (): void {
    $this->artisan('ai:trend-read', ['range' => '14d'])
        ->expectsOutputToContain('range must be one of: 7d')
        ->assertFailed();
});

it('rejects a retired range now that only 7d is scheduled', function (string $range): void {
    $this->artisan('ai:trend-read', ['range' => $range])
        ->expectsOutputToContain('range must be one of: 7d')
        ->assertFailed();
})->with(['30d', '90d', '12mo']);

it('skips the demo user even with recent activity', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    User::factory()->demo()->seenToday()->create();

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '7d'])
        ->expectsOutputToContain('Dispatched trend read (7d) for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('skips a user who has not opened the app in the active window, however recently they ran', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create(['last_seen_at' => Carbon::today()->subDays(30)]);
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '7d'])
        ->expectsOutputToContain('Dispatched trend read (7d) for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('re-dispatches a done 7d read whose stored fingerprint is null, exactly once', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
    Bus::fake();

    $user = User::factory()->seenToday()->create();
    $row = Analysis::factory()->done('holding steady this week')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '7d',
    ]);

    $this->artisan('ai:trend-read', ['range' => '7d'])->assertSuccessful();

    Bus::assertDispatchedTimes(AnalyzeTrendReadJob::class, 1);
    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued);

    Carbon::setTestNow();
});

it('does not re-dispatch a done read whose stamped fingerprint still matches the current numbers', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
    Bus::fake();

    $user = User::factory()->seenToday()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'trimp_edwards' => 100.0,
        'distance' => 5000.0,
        'start_date_local' => Carbon::today()->subDays(2),
    ]);

    $row = Analysis::factory()->done('holding steady')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '7d',
        'content_fingerprint' => MaterialFingerprint::forTrendRead(currentTrendTotals($user, '7d')),
    ]);

    $this->artisan('ai:trend-read', ['range' => '7d'])->assertSuccessful();

    Bus::assertNotDispatched(AnalyzeTrendReadJob::class);
    $row->refresh();
    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and($row->content)->toBe('holding steady');

    Carbon::setTestNow();
});

it('re-dispatches a done read once when its range material has changed', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');
    Bus::fake();

    $user = User::factory()->seenToday()->create();

    $row = Analysis::factory()->done('old read')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '7d',
        'content_fingerprint' => MaterialFingerprint::forTrendRead(currentTrendTotals($user, '7d')),
    ]);

    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create([
        'trimp_edwards' => 150.0,
        'distance' => 8000.0,
        'start_date_local' => Carbon::today()->subDays(1),
    ]);

    $this->artisan('ai:trend-read', ['range' => '7d'])->assertSuccessful();

    Bus::assertDispatchedTimes(AnalyzeTrendReadJob::class, 1);
    expect($row->fresh()->status)->toBe(AnalysisStatus::Queued);

    Carbon::setTestNow();
});
