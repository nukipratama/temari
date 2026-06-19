<?php

declare(strict_types=1);

use App\Models\Activity;
use App\Models\AI\Analysis;
use App\Models\User;
use App\Services\AI\AnalysisService;
use App\Services\AI\AnalysisType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

/**
 * Capture every AnalysisService::request() call as a flat array of the args the
 * command cares about, returning a throwaway Analysis row.
 *
 * @param  array<int, array<string, mixed>>  $captured
 */
function captureWeeklyProfileRequests(array &$captured): AnalysisService
{
    $service = Mockery::mock(AnalysisService::class);
    $service->shouldReceive('request')
        ->andReturnUsing(function (string $subjectOrType, int $subjectId, AnalysisType $type, ?string $discriminator = null, ?int $delaySeconds = null, bool $invalidate = false) use (&$captured): Analysis {
            $captured[] = compact('subjectOrType', 'subjectId', 'type', 'discriminator', 'invalidate');

            return new Analysis();
        });

    return $service;
}

it('refreshes persona (week-keyed, invalidate:false) and Kata Temari (invalidate:true) for an active user', function (): void {
    // Monday 2026-05-18, ISO week 2026-W21.
    Carbon::setTestNow('2026-05-18 00:05:00');

    $user = User::factory()->create();
    Activity::factory()->for($user)->create(['analyzed_at' => Carbon::now()]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureWeeklyProfileRequests($captured));

    $this->artisan('ai:weekly-profile')
        ->expectsOutputToContain('Dispatched weekly profile refresh for 1 active users')
        ->assertSuccessful();

    expect($captured)->toHaveCount(2);

    $persona = collect($captured)->firstWhere('type', AnalysisType::PersonaSummary);
    expect($persona['subjectOrType'])->toBe(AnalysisType::PERSONA_SUMMARY_SUBJECT_TYPE)
        ->and($persona['subjectId'])->toBe($user->id)
        ->and($persona['discriminator'])->toBe('2026-W21')
        ->and($persona['invalidate'])->toBeFalse();

    $voice = collect($captured)->firstWhere('type', AnalysisType::AkuProfileVoice);
    expect($voice['subjectOrType'])->toBe(AnalysisType::AKU_PROFILE_VOICE_SUBJECT_TYPE)
        ->and($voice['subjectId'])->toBe($user->id)
        ->and($voice['discriminator'])->toBeNull()
        ->and($voice['invalidate'])->toBeTrue();

    Carbon::setTestNow();
});

it('excludes the demo user so it never auto-bills the weekly profile LLM', function (): void {
    Carbon::setTestNow('2026-05-18 00:05:00');

    $real = User::factory()->create();
    Activity::factory()->for($real)->create(['analyzed_at' => Carbon::now()]);
    $demo = User::factory()->demo()->create();
    Activity::factory()->for($demo)->create(['analyzed_at' => Carbon::now()]);

    $captured = [];
    $this->app->instance(AnalysisService::class, captureWeeklyProfileRequests($captured));

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
