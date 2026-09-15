<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\ActivityDetail;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/** A run on $day, ingested (and so stamped analyzed_at) just now. */
function ranOn(User $user, Carbon $day): void
{
    $activity = Activity::factory()->for($user)->create(['analyzed_at' => Carbon::now()]);
    ActivityDetail::factory()->for($activity)->create(['start_date_local' => $day]);
}

it('refreshes the Temari note voice once, week-keyed and invalidate:false, for an active user', function (): void {
    // Monday 2026-05-18, ISO week 2026-W21.
    Carbon::setTestNow('2026-05-18 00:05:00');

    $user = User::factory()->seenToday()->create();

    $captured = [];
    $this->app->instance(AnalysisService::class, captureAnalysisServiceRequests($captured));

    $this->artisan('ai:weekly-profile')
        ->expectsOutputToContain('Dispatched weekly profile refresh for 1 active users')
        ->assertSuccessful();

    // One request per user per week, not two: the week key is the refresh, so a
    // mid-week "Reread" is never re-billed by the scheduler.
    expect($captured)->toHaveCount(1);

    $voice = $captured[0];
    expect($voice['type'])->toBe(AnalysisType::ProfileVoice)
        ->and($voice['subjectOrType'])->toBe(AnalysisType::PROFILE_VOICE_SUBJECT_TYPE)
        ->and($voice['subjectId'])->toBe($user->id)
        ->and($voice['discriminator'])->toBe('2026-W21')
        ->and($voice['invalidate'])->toBeFalse();

    Carbon::setTestNow();
});

it('excludes the demo user so it never auto-bills the weekly profile LLM', function (): void {
    Carbon::setTestNow('2026-05-18 00:05:00');

    $real = User::factory()->seenToday()->create();
    $demo = User::factory()->demo()->seenToday()->create();

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

it('skips a user who has not opened the app in the last 7 days', function (): void {
    Carbon::setTestNow('2026-05-18 00:05:00');

    User::factory()->create(['last_seen_at' => Carbon::now()->subDays(10)]);

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:weekly-profile')
        ->expectsOutputToContain('Dispatched weekly profile refresh for 0 active users')
        ->assertSuccessful();

    Carbon::setTestNow();
});

it('skips an athlete who is running but not opening the app', function (): void {
    // A run syncs itself in without anyone looking, so run recency says nothing
    // about whether the voice this bills for will ever be read.
    Carbon::setTestNow('2026-05-18 00:05:00');

    $dormant = User::factory()->create(['last_seen_at' => Carbon::now()->subDays(10)]);
    ranOn($dormant, Carbon::now());

    $service = Mockery::mock(AnalysisService::class);
    $service->shouldNotReceive('request');
    $this->app->instance(AnalysisService::class, $service);

    $this->artisan('ai:weekly-profile')
        ->expectsOutputToContain('Dispatched weekly profile refresh for 0 active users')
        ->assertSuccessful();

    Carbon::setTestNow();
});
