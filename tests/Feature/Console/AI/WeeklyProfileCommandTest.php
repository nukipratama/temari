<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('refreshes the Kata Temari voice once, week-keyed and invalidate:false, for an active user', function (): void {
    // Monday 2026-05-18, ISO week 2026-W21.
    Carbon::setTestNow('2026-05-18 00:05:00');

    $user = User::factory()->create();
    Activity::factory()->for($user)->create(['analyzed_at' => Carbon::now()]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:weekly-profile')
        ->expectsOutputToContain('Dispatched weekly profile refresh for 1 active users')
        ->assertSuccessful();

    // One request per user per week, not two: the week key is the refresh, so a
    // mid-week "Baca ulang" is never re-billed by the scheduler.
    expect($captured)->toHaveCount(1);

    $voice = $captured[0];
    expect($voice['type'])->toBe(AnalysisType::AkuProfileVoice)
        ->and($voice['subjectOrType'])->toBe(AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE)
        ->and($voice['subjectId'])->toBe($user->id)
        ->and($voice['discriminator'])->toBe('2026-W21')
        ->and($voice['invalidate'])->toBeFalse();

    Carbon::setTestNow();
});

it('excludes the demo user so it never auto-bills the weekly profile LLM', function (): void {
    Carbon::setTestNow('2026-05-18 00:05:00');

    $real = User::factory()->create();
    Activity::factory()->for($real)->create(['analyzed_at' => Carbon::now()]);
    $demo = User::factory()->demo()->create();
    Activity::factory()->for($demo)->create(['analyzed_at' => Carbon::now()]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:weekly-profile')
        ->expectsOutputToContain('Dispatched weekly profile refresh for 1 active users')
        ->assertSuccessful();

    expect(array_column($captured, 'subjectId'))
        ->toContain($real->id)
        ->not->toContain($demo->id);

    Carbon::setTestNow();
});

it('skips a user with no run analyzed in the last 7 days', function (): void {
    Carbon::setTestNow('2026-05-18 00:05:00');

    $stale = User::factory()->create();
    Activity::factory()->for($stale)->create(['analyzed_at' => Carbon::now()->subDays(10)]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:weekly-profile')
        ->expectsOutputToContain('Dispatched weekly profile refresh for 0 active users')
        ->assertSuccessful();

    Carbon::setTestNow();
});
