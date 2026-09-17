<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisStatus;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('dispatches the requested range for every active user', function (): void {
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
    $this->artisan('ai:trend-read', ['range' => '14d'])
        ->expectsOutputToContain('range must be one of: 7d, 30d, 90d, 12mo')
        ->assertFailed();
});

it('skips the demo user even with recent activity', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    User::factory()->demo()->seenToday()->create();

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:trend-read', ['range' => '30d'])
        ->expectsOutputToContain('Dispatched trend read (30d) for 0 active users.')
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

    $this->artisan('ai:trend-read', ['range' => '30d'])
        ->expectsOutputToContain('Dispatched trend read (30d) for 0 active users.')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('dispatches each real range with its own discriminator', function (string $range): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->seenToday()->create();

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

it('leaves an already-done 7d read untouched on the next cron run, never re-billing it', function (): void {
    Carbon::setTestNow('2026-08-17 12:00:00');

    $user = User::factory()->seenToday()->create();
    $row = Analysis::factory()->done('holding steady this week')->create([
        'subject_type' => AnalysisType::TREND_READ_SUBJECT_TYPE,
        'subject_id' => $user->id,
        'analysis_type' => AnalysisType::TrendRead,
        'discriminator' => '7d',
    ]);
    $updatedAt = $row->updated_at;

    $this->artisan('ai:trend-read', ['range' => '7d'])->assertSuccessful();

    $row->refresh();
    expect($row->status)->toBe(AnalysisStatus::Done)
        ->and($row->content)->toBe('holding steady this week')
        ->and($row->updated_at->eq($updatedAt))->toBeTrue();

    Carbon::setTestNow();
});
