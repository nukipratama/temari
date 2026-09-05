<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('dispatches the requested range for every active user', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);

    $requestCalls = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null) use (&$requestCalls): Analysis {
            $requestCalls[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator');

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '30d'])
        ->expectsOutputToContain('Dispatched trend read (30d) for 1 active users.')
        ->assertSuccessful();

    expect($requestCalls)->toHaveCount(1)
        ->and($requestCalls[0]['subjectOrType'])->toBe(AnalysisType::TREND_READ_SUBJECT_TYPE)
        ->and($requestCalls[0]['subjectId'])->toBe($user->id)
        ->and($requestCalls[0]['type'])->toBe(AnalysisType::TrendRead)
        ->and($requestCalls[0]['discriminator'])->toBe('30d');

    Carbon::setTestNow();
});

it('rejects a range outside AnalysisType::TREND_READ_RANGES', function (): void {
    $this->artisan('ai:trend-read', ['range' => '7d'])
        ->expectsOutputToContain('range must be one of: 30d, 90d, 12mo')
        ->assertFailed();
});

it('skips the demo user even with recent activity', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $demo = User::factory()->demo()->create();
    $activity = Activity::factory()->for($demo)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '30d'])
        ->expectsOutputToContain('Dispatched trend read (30d) for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('skips a user with no run in the active window', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()->subDays(30)]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '30d'])
        ->expectsOutputToContain('Dispatched trend read (30d) for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('dispatches each of the three real ranges with its own discriminator', function (string $range): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->create();
    $activity = Activity::factory()->for($user)->create();
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => Carbon::today()]);

    $requestCalls = [];
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->once()
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null) use (&$requestCalls): Analysis {
            $requestCalls[] = $discriminator;

            return new Analysis();
        });
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => $range])->assertSuccessful();

    expect($requestCalls)->toBe([$range]);

    Carbon::setTestNow();
})->with(AnalysisType::TREND_READ_RANGES);
